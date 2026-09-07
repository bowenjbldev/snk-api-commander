<?php

namespace Packages\Kilkana\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Resources\SnkKilkanaRoleResource;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\Permission\Models\Role;

/**
 * Los papeles del periódico, de solo lectura.
 *
 * Están partidos por poder editorial y no por verbos, así que no se editan por aquí: lo que
 * hace cada uno lo escribe {@see SnkKilkanaRoleCatalogService} y esto lo cuenta. Cada papel
 * viaja con sus permisos y con cuánta gente lo tiene, que es lo que la matriz del panel pinta.
 *
 * Tras `index users` y no tras el permiso que abre el panel: quien reparte asientos es quien
 * necesita ver el reparto entero.
 */
class SnkKilkanaRolesController extends CoreBaseApiController
{
    protected string $modelClass = Role::class;

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission('index users')) {
            return $response;
        }

        // `withCount('users')` de Spatie cuelga del guard de la instancia y sin instancia cae al
        // guard por defecto; contar el pivote a mano no depende de eso.
        $cuentas = DB::table('model_has_roles')
            ->where('model_type', (new CoreUserModel)->getMorphClass())
            ->selectRaw('role_id, count(distinct model_id) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles = Role::query()
            ->where('guard_name', 'sanctum')
            ->with('permissions:id,name')
            ->orderBy('id')
            ->get()
            // El recuento se cuelga de la fila para que el Resource lo lea como cualquier otro
            // atributo: viene de una sola consulta agrupada, no de una por papel.
            ->each(fn (Role $rol) => $rol->setAttribute('users_count', (int) $cuentas->get($rol->id, 0)));

        return response()->json(SnkKilkanaRoleResource::collection($roles));
    }
}
