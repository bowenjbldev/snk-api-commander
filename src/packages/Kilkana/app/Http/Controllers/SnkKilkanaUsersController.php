<?php

namespace Packages\Kilkana\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Core\Http\Controllers\CoreUsersController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Resources\SnkKilkanaUserResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaAccountLinkService;
use Packages\Kilkana\Services\SnkKilkanaActivityService;
use Packages\Kilkana\Services\SnkKilkanaBylineService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;

/**
 * Las cuentas de la redacción: quién ocupa asiento, con qué papel y en qué estado.
 *
 * **Un asiento es lo que se factura; un papel es lo que se puede hacer.** Por aquí pasan solo
 * los papeles del panel: un lector no ocupa asiento y no sale en el listado ni se puede
 * asignar desde él. Y tres estados, no dos: **invitada** —ocupa asiento desde que se invita,
 * que es lo que impide vender dos veces la misma plaza—, **activa** y **suspendida** —sin paso
 * al panel, con el asiento guardado y lo publicado firmado, y por eso se deshace—.
 */
class SnkKilkanaUsersController extends CoreUsersController
{
    public function __construct(
        private readonly SnkKilkanaBylineService $bylines,
        private readonly SnkKilkanaAccountLinkService $links,
        private readonly SnkKilkanaActivityService $registro,
    ) {
        // Un puesto del panel: ni lector ni cuenta de servicio entran por aquí.
        $sinAsiento = 'not_in:'.implode(',', SnkKilkanaRoleCatalogService::WITHOUT_SEAT);
        $this->validationRulesStore['role'] .= "|{$sinAsiento}";
        $this->validationRulesUpdate['role'] .= "|{$sinAsiento}";
    }

    /**
     * El listado dice qué ROL tiene cada cuenta, en qué estado está y cuánto ha hecho.
     *
     * `CoreUserModel` esconde la relación `roles` en serialización, y hace bien: destaparla
     * arrastraría el pivot de Spatie en cada usuario de cada respuesta. Este endpoint reparte los
     * roles del periódico, así que añade UN campo plano —el nombre del rol—. Uno solo: Spatie
     * admite varios roles por usuario, pero un periódico reparte un puesto por persona.
     *
     * `last_active_at` es la última vez que un token suyo se usó, y `posts_count` cuenta lo que
     * firma su cuenta: son las dos cifras que dicen si un asiento se usa.
     *
     * Sin paginar a propósito: los asientos los limita el plan, así que la lista es corta por
     * construcción.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $users = $this->newQuery()
            ->whereHas('roles', fn ($query) => $query->whereNotIn('name', SnkKilkanaRoleCatalogService::WITHOUT_SEAT))
            ->with('roles:id,name')
            ->withMax('tokens as last_active_at', 'last_used_at')
            ->orderBy('name')
            ->get();

        $piezas = SnkKilkanaPostModel::query()
            ->join('kilkana_authors', 'kilkana_authors.id', '=', 'kilkana_posts.author_id')
            ->whereIn('kilkana_authors.user_id', $users->modelKeys())
            ->groupBy('kilkana_authors.user_id')
            ->selectRaw('kilkana_authors.user_id as user_id, count(*) as total')
            ->pluck('total', 'user_id');

        // El recuento se cuelga de la fila: viene de una consulta agrupada para toda la lista,
        // no de un `withCount` por cuenta, porque cruza `kilkana_authors` por el camino.
        $users->each(fn (CoreUserModel $user) => $user->setAttribute('posts_count', (int) $piezas->get($user->getKey(), 0)));

        return response()->json(SnkKilkanaUserResource::collection($users));
    }

    /**
     * Un asiento nuevo: la cuenta nace invitada y el correo lleva el enlace para entrar.
     *
     * Con una contraseña que nadie conoce: la primera la pone su dueño desde el enlace, y
     * hasta entonces la cuenta ocupa asiento y no entra. El correo tiene que salir: si no
     * sale, la cuenta no se crea, porque una invitada sin invitación es un asiento pagado
     * al que nadie puede llegar.
     */
    public function invite(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'email' => $this->validationRulesStore['email'],
            'full_name' => $this->validationRulesStore['full_name'],
            'role' => $this->validationRulesStore['role'],
        ]);

        $user = new CoreUserModel([
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'password' => Str::password(40),
        ]);
        $user->forceFill(['invited_at' => now()])->save();
        $user->assignRole($data['role']);

        try {
            $this->links->send($user, SnkKilkanaAccountLinkService::INVITE, $request);
        } catch (\Throwable $fallo) {
            report($fallo);
            $user->delete();

            return response()->json(['error' => 'Invitation could not be sent'], 502);
        }

        $user->load('roles:id,name');
        $this->registro->log('seat', 'seat_invited', $data['email'], '/roles', ['params' => ['role' => $data['role']]]);

        return $this->flagPendingByline(response()->json(new SnkKilkanaUserResource($user), 201), (int) $user->getKey());
    }

    /** El mismo enlace otra vez, solo mientras la cuenta siga invitada. */
    public function resendInvitation(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $user = $this->panelAccount($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($user->invited_at === null) {
            return response()->json(['error' => 'Account has already joined'], 409);
        }

        return $this->mail($user, SnkKilkanaAccountLinkService::INVITE, $request);
    }

    /** El enlace de contraseña, para quien ya entró alguna vez. A una invitada se le reenvía la invitación. */
    public function sendPasswordLink(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $user = $this->panelAccount($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($user->invited_at !== null) {
            return response()->json(['error' => 'Account has not joined yet'], 409);
        }

        return $this->mail($user, SnkKilkanaAccountLinkService::RESET, $request);
    }

    /**
     * Cierra el paso al panel sin soltar el asiento.
     *
     * Tira sus sesiones en el acto: suspender es para una cuenta que se ha ido de las manos, y
     * un token vivo hasta que caduque es justo lo que hay que cortar. Nadie se suspende a sí
     * mismo: sería cerrarse la puerta desde dentro sin nadie que la abra.
     */
    public function suspend(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        if ($response = $this->notSelf($id, 'You cannot suspend yourself')) {
            return $response;
        }

        $user = $this->panelAccount($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($user->invited_at !== null) {
            return response()->json(['error' => 'Account has not joined yet'], 409);
        }

        $user->forceFill(['suspended_at' => now()])->save();
        $user->tokens()->delete();
        $this->registro->log('seat', 'seat_suspended', $user->full_name ?: $user->name, '/roles');

        return response()->json(new SnkKilkanaUserResource($user->load('roles:id,name')));
    }

    /** Y se deshace: la cuenta vuelve a entrar con su contraseña de siempre. */
    public function restore(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $user = $this->panelAccount($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $user->forceFill(['suspended_at' => null])->save();
        $this->registro->log('seat', 'seat_restored', $user->full_name ?: $user->name, '/roles');

        return response()->json(new SnkKilkanaUserResource($user->load('roles:id,name')));
    }

    /** Alta con rol de escritura = alta con firma. Ver `ensureByline`. */
    public function store(Request $request): JsonResponse
    {
        $response = parent::store($request);
        if ($response->getStatusCode() === 201) {
            return $this->flagPendingByline($response, (int) ($response->getData(true)['id'] ?? 0));
        }

        return $response;
    }

    /**
     * También al CAMBIAR de rol: quien pasa a escribir necesita firma desde ese momento.
     *
     * Y nadie cambia su propio papel: el único administrador que se baja a redactor se queda
     * sin nadie que pueda devolverle el puesto.
     */
    public function update(Request $request, $id = null): JsonResponse
    {
        if ($request->filled('role') && (int) $id === (int) auth()->id()) {
            return response()->json(['role' => ['You cannot change your own role.']], 422);
        }

        $response = parent::update($request, $id);
        if ($response->getStatusCode() === 200) {
            return $this->flagPendingByline($response, (int) $id);
        }

        return $response;
    }

    /**
     * Borrar una cuenta dice QUÉ lo impide.
     *
     * `kilkana_authors.user_id` es RESTRICT: la identidad de una firma es su cuenta, así que
     * borrarla dejaría la hemeroteca firmada por nadie. Saltaba como 500 con el SQL dentro.
     *
     * El orden importa: primero se retira la firma —decisión editorial, y ahí es donde se ve
     * cuántos artículos arrastra—, y solo después se da de baja la cuenta. Y nadie se retira a
     * sí mismo: un periódico sin administrador no tiene quien lo arregle.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }
        if ($response = $this->notSelf($id, 'You cannot remove yourself')) {
            return $response;
        }

        $firma = SnkKilkanaAuthorModel::query()->where('user_id', $id)->first(['id', 'slug']);
        if ($firma) {
            return response()->json([
                'error' => 'Account still signs',
                'byline' => ['id' => $firma->id, 'slug' => $firma->slug],
            ], 409);
        }

        try {
            return parent::destroy($id);
        } catch (QueryException) {
            return response()->json(['error' => 'Account still signs'], 409);
        }
    }

    /** Una cuenta del panel por id: un lector no está aquí ni para verlo. */
    private function panelAccount($id): ?CoreUserModel
    {
        return $this->newQuery()
            ->whereKey($id)
            ->whereHas('roles', fn ($query) => $query->whereNotIn('name', SnkKilkanaRoleCatalogService::WITHOUT_SEAT))
            ->first();
    }

    private function notSelf($id, string $motivo): ?JsonResponse
    {
        return (int) $id === (int) auth()->id()
            ? response()->json(['error' => $motivo], 409)
            : null;
    }

    /** Manda el enlace y dice cuándo, o dice que no pudo. */
    private function mail(CoreUserModel $user, string $kind, Request $request): JsonResponse
    {
        try {
            $this->links->send($user, $kind, $request);
        } catch (\Throwable $fallo) {
            report($fallo);

            return response()->json(['error' => 'Email could not be sent'], 502);
        }

        return response()->json(['sent_at' => now()->toIso8601String()]);
    }

    /**
     * La cuenta se guarda igual, pero DICE si se ha quedado sin firma.
     *
     * Sin esto el aviso no existe: la cuenta aparece en el listado como cualquier otra y solo
     * al ir a atribuirle un artículo se descubre que no puede firmar. `byline_pending` viaja
     * en la respuesta del guardado —no es estado, es lo que acaba de pasar— para que el consumidor
     * pueda avisar de la firma pendiente.
     */
    private function flagPendingByline(JsonResponse $response, int $userId): JsonResponse
    {
        if (! $this->bylines->ensureForUser($userId)) {
            return $response;
        }

        return $response->setData($response->getData(true) + ['byline_pending' => true]);
    }
}
