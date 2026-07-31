<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Kilkana\Services\SnkKilkanaMediaTaxonomyService;

/**
 * Etiquetas y colecciones de la biblioteca tras un solo juego de rutas.
 *
 * `$taxonomy` es un parámetro de ruta y va DECLARADO, no leído de la petición. Aquí no
 * estaba, y los parámetros sin tipo se asignan por posición: `$id` recibía «tags», así que
 * `find('tags')` no encontraba nada y **renombrar o borrar una etiqueta respondía 404
 * siempre**. El listado y el alta funcionaban —no llevan `{id}`—, así que la mitad rota no
 * se veía desde fuera.
 *
 * Los permisos los pone el middleware `can:` de las rutas, como en composición.
 */
#[Group('Media taxonomies')]
class SnkKilkanaMediaTaxonomiesController
{
    public function __construct(private readonly SnkKilkanaMediaTaxonomyService $taxonomies) {}

    public function index(string $taxonomy): JsonResponse
    {
        return response()->json($this->taxonomies->all($taxonomy));
    }

    public function store(Request $request, string $taxonomy): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120|unique:'.$this->taxonomies->table($taxonomy).',name',
        ]);

        return response()->json($this->taxonomies->create($taxonomy, $data), 201);
    }

    public function update(Request $request, string $taxonomy, $id): JsonResponse
    {
        $row = $this->taxonomies->find($taxonomy, $id);
        if (! $row) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120|unique:'.$row->getTable().',name,'.$row->id,
        ]);

        return response()->json($this->taxonomies->rename($row, $data));
    }

    /**
     * Borra y DICE a cuántos medios ha afectado.
     *
     * El pivote se va por CASCADE sin preguntar. Que la respuesta lo cuente es lo que
     * distingue «he borrado una etiqueta que no usaba nadie» de «acabo de desetiquetar
     * cincuenta fotos», que desde fuera se veían igual.
     */
    public function destroy(string $taxonomy, $id): JsonResponse
    {
        $row = $this->taxonomies->find($taxonomy, $id);
        if (! $row) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json([
            'id' => (string) $id,
            'detached' => $this->taxonomies->remove($row),
        ]);
    }
}
