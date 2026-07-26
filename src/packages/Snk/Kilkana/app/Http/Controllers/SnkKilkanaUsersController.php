<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreUsersController;
use Packages\Snk\Kilkana\Services\SnkKilkanaBylineService;

class SnkKilkanaUsersController extends CoreUsersController
{
    public function __construct(private readonly SnkKilkanaBylineService $bylines) {}

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
        if (! $this->bylines->ensureForUser($userId)) {
            return $response;
        }

        return $response->setData($response->getData(true) + ['byline_pending' => true]);
    }
}
