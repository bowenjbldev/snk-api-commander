<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;

#[Group('Categories')]
class SnkKilkanaCategoriesController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaCategoryModel::class;

    protected string $indexPermission = 'index categories';

    protected string $showPermission = 'show categories';

    protected string $storePermission = 'create categories';

    protected string $updatePermission = 'update categories';

    protected string $destroyPermission = 'delete categories';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'slug' => 'nullable|string|max:64|unique:kilkana_categories,slug',
        'parent_id' => 'nullable|integer|exists:kilkana_categories,id',
        'description' => 'nullable|string',
        'position' => 'nullable|integer|min:0|max:65535',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'slug' => 'nullable|string|max:64|unique:kilkana_categories,slug,{id}',
        // Primera linea contra el ciclo trivial. La forma del arbol —dos niveles y sin
        // ciclos— la impone `SnkKilkanaCategoryModel::guardTree`, que tambien cubre al
        // seeder y a la consola, que no pasan por aqui.
        'parent_id' => 'nullable|integer|exists:kilkana_categories,id|not_in:{id}',
        'description' => 'nullable|string',
        'position' => 'nullable|integer|min:0|max:65535',
    ];

    /**
     * Borrar dice QUE lo impide, en vez de reventar con el SQL a la vista.
     *
     * Dos claves foraneas protegen una seccion —sus articulos y sus subsecciones, las dos
     * RESTRICT— y al saltar devolvian un 500 con el nombre de la tabla y de la restriccion
     * dentro. Quien lo recibia no podia distinguir «esta seccion tiene contenido» de «el
     * servidor se ha roto», y de paso se publicaba el esquema.
     *
     * Se cuenta antes de borrar para poder nombrar los DOS motivos a la vez: la base solo
     * informa del primero con el que tropieza, y arreglar uno para descubrir el otro es un
     * viaje de ida y vuelta que no hace falta.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $posts = SnkKilkanaPostModel::query()->where('category_id', $id)->count();
        $subsecciones = SnkKilkanaCategoryModel::query()->where('parent_id', $id)->count();

        if ($posts > 0 || $subsecciones > 0) {
            return response()->json([
                'error' => 'Section in use',
                'posts' => $posts,
                'subsections' => $subsecciones,
            ], 409);
        }

        try {
            return parent::destroy($id);
        } catch (QueryException) {
            // Red de seguridad: entre el recuento y el borrado cabe una escritura, y manana
            // puede haber una tercera clave foranea que este metodo no conozca. Nunca un 500
            // con SQL dentro.
            return response()->json(['error' => 'Section in use'], 409);
        }
    }
}
