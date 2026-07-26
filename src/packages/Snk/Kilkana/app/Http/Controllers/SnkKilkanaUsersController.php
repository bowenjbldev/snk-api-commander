<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Packages\Core\Http\Controllers\CoreUsersController;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;

class SnkKilkanaUsersController extends CoreUsersController
{
    /**
     * El listado dice qué ROL tiene cada cuenta.
     *
     * `CoreUserModel` esconde la relación `roles` en serialización, y hace bien: destaparla
     * arrastraría el pivot de Spatie en cada usuario de cada respuesta. Pero esta pantalla
     * es justo la que reparte los roles del periódico, así que aquí se añade UN campo plano
     * —el nombre del rol— en vez de abrir la relación entera.
     *
     * Uno solo: Spatie admite varios roles por usuario, pero un periódico reparte un puesto
     * por persona y `store()` acepta `role` en singular.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $users = $this->newQuery()
            ->with('roles:id,name')
            ->get()
            ->each(fn ($user) => $user->setAttribute('role', $user->roles->first()?->name));

        return response()->json($users);
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

    /** También al CAMBIAR de rol: quien pasa a escribir necesita firma desde ese momento. */
    public function update(Request $request, $id = null): JsonResponse
    {
        $response = parent::update($request, $id);
        if ($response->getStatusCode() === 200) {
            return $this->flagPendingByline($response, (int) $id);
        }

        return $response;
    }

    /**
     * La cuenta se guarda igual, pero DICE si se ha quedado sin firma.
     *
     * Sin esto el aviso no existe: la cuenta aparece en el listado como cualquier otra y solo
     * al ir a atribuirle un artículo se descubre que no puede firmar. `byline_pending` viaja
     * en la respuesta del guardado —no es estado, es lo que acaba de pasar— y el panel lo
     * convierte en un aviso hacia /admin/authors.
     */
    private function flagPendingByline(JsonResponse $response, int $userId): JsonResponse
    {
        if (! $this->ensureByline($userId)) {
            return $response;
        }

        return $response->setData($response->getData(true) + ['byline_pending' => true]);
    }

    /**
     * Si la cuenta puede escribir, tiene firma.
     *
     * Una firma ES una cuenta, así que dar de alta a un periodista y NO darle firma dejaba
     * la mitad del trabajo hecho: podía escribir pero no firmar, y tenía que atribuirse a
     * un compañero. La condición es el permiso, no una lista de roles: un rol nuevo que
     * pueda escribir entra solo.
     *
     * No hay operación inversa. Bajar de rol NO borra la firma: su hemeroteca sigue
     * publicada y `posts.author_id` es RESTRICT — retirar una firma es una decisión
     * editorial, y se toma en su pantalla.
     *
     * @return bool true si la cuenta DEBÍA tener firma y se ha quedado sin ella. No es lo
     *              mismo que no tenerla: quien modera comentarios no la necesita.
     */
    private function ensureByline(int $userId): bool
    {
        $user = CoreUserModel::query()->find($userId);
        if ($user === null || ! $user->getAllPermissions()->pluck('name')->contains('create posts')) {
            return false;
        }

        try {
            // Sin nombre: firma con el de su cuenta hasta que alguien le ponga un alias. La
            // URL sale de `deriveSlug`, que se para antes que inventar un sufijo.
            SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $user->id]);

            return false;
        } catch (ValidationException|QueryException) {
            // Las URLs derivables están ocupadas (o dos altas idénticas a la vez se pisaron
            // contra el UNIQUE). Guardar la CUENTA no puede fallar por esto: queda sin firma
            // y sale en el selector de «Nueva firma», que es donde se elige una URL.
            return true;
        }
    }
}
