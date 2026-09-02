<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

#[Group('Tags')]
class SnkKilkanaTagsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaTagModel::class;

    protected string $indexPermission = 'index tags';

    protected string $showPermission = 'show tags';

    protected string $storePermission = 'create tags';

    protected string $updatePermission = 'update tags';

    protected string $destroyPermission = 'delete tags';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug,{id}',
    ];

    /**
     * Cada etiqueta sale con cuanto se usa y cuando se uso por ultima vez.
     *
     * Sin las dos cifras esta pantalla no existe: lo que se mira de un vocabulario no es la
     * lista de palabras —eso ya lo sabe quien lo escribio— sino su FORMA, y la forma son las
     * cuentas: cuatro etiquetas cargando con la mitad del archivo y una cola de veinte que
     * nadie vuelve a escribir igual. Contarlas en el cliente pedia el archivo entero.
     *
     * `last_used_at` mira solo lo publicado, igual que en firmas: un borrador de hoy no es uso
     * del vocabulario, es una intencion. `posts_count` cuenta todo lo que la lleva, incluidos
     * borradores, porque para fundir importa cuantas piezas hay que mover, no cuantas se ven.
     *
     * Va en `newQuery()` y no en el modelo porque solo lo quiere quien LISTA: pedir una
     * etiqueta para renombrarla no necesita recorrer su pivote.
     */
    protected function newQuery()
    {
        return parent::newQuery()
            ->withCount('posts')
            ->withMax(['posts as last_used_at' => fn ($query) => $query->published()], 'publish_at')
            ->orderBy('name');
    }

    /**
     * Vuelca una etiqueta en otra: la superviviente se queda con sus piezas y la otra desaparece.
     *
     * Es la operacion de esta pantalla, y no es «mover y luego borrar»: entre las dos mitades un
     * fallo dejaria dos etiquetas con las mismas piezas y ninguna forma de saber cual era la
     * buena. Por eso va en una transaccion, y por eso el pivote se sincroniza SIN desatar:
     * una pieza que llevara las dos no puede entrar dos veces.
     *
     * Pide `delete tags` y no `update tags`: al terminar hay una etiqueta menos, y quien puede
     * renombrar el vocabulario no es necesariamente quien puede recortarlo.
     */
    public function merge(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $desde = SnkKilkanaTagModel::query()->find($id);
        if ($desde === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // `Rule::notIn` y no `different:{$id}` interpolado: la cadena de reglas se parte por `|`,
        // asi que un id con esa barra —y la ruta lo acepta— se leia como una regla mas y devolvia
        // un 500 con `Validator::validateX does not exist` dentro. La regla es la misma; lo que
        // cambia es que el valor viaja como valor.
        $data = $request->validate([
            'into' => ['required', 'integer', Rule::notIn([$desde->id]), 'exists:kilkana_tags,id'],
        ]);

        $hasta = SnkKilkanaTagModel::query()->findOrFail($data['into']);
        $piezas = $desde->posts()->pluck('kilkana_posts.id')->all();

        // Callado: fundir borra una etiqueta, y «borró la etiqueta» no es lo que pasó.
        $registro = app(SnkKilkanaActivityService::class);
        $registro->quiet(fn () => DB::transaction(function () use ($desde, $hasta, $piezas) {
            $hasta->posts()->syncWithoutDetaching($piezas);
            $desde->delete();
        }));
        $registro->log('tag', 'merged the tags', "{$desde->name} into {$hasta->name}", '/tags', ['before' => $desde->name, 'after' => $hasta->name]);

        return response()->json(['id' => (string) $hasta->id, 'moved' => count($piezas)]);
    }

    /**
     * Borra y DICE a cuantas piezas ha desetiquetado.
     *
     * El pivote se va por CASCADE sin preguntar, asi que desde fuera «he borrado una etiqueta
     * que no usaba nadie» y «acabo de desetiquetar trescientos articulos» se veian igual. La
     * pantalla borra sin confirmar —lo que se pierde es una palabra, no las piezas—, y esta
     * cifra es lo unico que permite contarlo despues.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $etiqueta = SnkKilkanaTagModel::query()->find($id);
        if ($etiqueta === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $piezas = $etiqueta->posts()->count();
        $etiqueta->delete();

        return response()->json(['id' => (string) $id, 'detached' => $piezas]);
    }
}
