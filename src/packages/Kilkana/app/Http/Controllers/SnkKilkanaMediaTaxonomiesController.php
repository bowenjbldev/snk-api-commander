<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Packages\Kilkana\Services\SnkKilkanaMediaTaxonomyService;

/**
 * Etiquetas y colecciones de la biblioteca tras un solo juego de rutas.
 *
 * `$taxonomy` es un parámetro de ruta y va DECLARADO, no leído de la petición: los parámetros
 * sin tipo se asignan por posición, y sin él `$id` recibiría «tags», `find('tags')` no
 * encontraría nada y **renombrar o borrar una etiqueta respondería 404 siempre**, mientras el
 * listado y el alta —que no llevan `{id}`— seguirían funcionando.
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
     * Vuelca un término en otro: el destino se queda con todo y el origen desaparece.
     *
     * Es lo que hace un vocabulario que se ha duplicado —«Retratos» y «Retrato»— y la
     * alternativa desde fuera es mover foto a foto y borrar, que a mitad de camino deja dos
     * estantes con lo mismo.
     */
    public function merge(Request $request, string $taxonomy, $id): JsonResponse
    {
        $source = $this->taxonomies->find($taxonomy, $id);
        if (! $source) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // El id viaja como VALOR y no interpolado en la cadena de reglas: un `|` dentro parte la
        // cadena y lo que sigue se lee como una regla que no existe, o sea un 500 por lo que
        // alguien escriba en la URL.
        $data = $request->validate([
            'into' => [
                'required',
                'integer',
                Rule::notIn([$source->id]),
                'exists:'.$this->taxonomies->table($taxonomy).',id',
            ],
        ]);

        $target = $this->taxonomies->find($taxonomy, $data['into']);
        if (! $target) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json([
            'id' => (string) $target->id,
            'moved' => $this->taxonomies->merge($source, $target),
        ]);
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
