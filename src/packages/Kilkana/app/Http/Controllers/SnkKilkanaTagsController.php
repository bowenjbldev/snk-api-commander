<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Http\Resources\SnkKilkanaTagResource;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;
use Spatie\QueryBuilder\QueryBuilder;

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

    protected ?string $resourceClass = SnkKilkanaTagResource::class;

    protected array $allowedFields = ['id', 'name', 'slug'];

    /**
     * Cada etiqueta sale con cuánto se usa y cuándo se usó por última vez.
     *
     * Sin las dos cifras esta pantalla no existe: lo que se mira de un vocabulario no es la
     * lista de palabras —eso ya lo sabe quien lo escribió— sino su FORMA, y la forma son las
     * cuentas: cuatro etiquetas cargando con la mitad del archivo y una cola de veinte que
     * nadie vuelve a escribir igual. Contarlas en el cliente pediría el archivo entero.
     *
     * `last_used_at` mira solo lo publicado, igual que en firmas: un borrador de hoy no es uso
     * del vocabulario, es una intención. `posts_count` cuenta todo lo que la lleva, incluidos
     * borradores, porque para fundir importa cuántas piezas hay que mover, no cuántas se ven.
     *
     * Va en `decorate()` y no en el modelo porque solo lo quiere quien LISTA: pedir una
     * etiqueta para renombrarla no necesita recorrer su pivote. Y detrás de `?fields=`, porque
     * el recorte hace `select()` y se llevaría las dos cifras.
     */
    protected function decorate(QueryBuilder $query): QueryBuilder
    {
        return $query->asCatalogue();
    }

    /**
     * Vuelca una etiqueta en otra: la superviviente se queda con sus piezas y la otra desaparece.
     *
     * Es la operación de esta pantalla, y no es «mover y luego borrar»: entre las dos mitades un
     * fallo dejaría dos etiquetas con las mismas piezas y ninguna forma de saber cuál era la
     * buena. Por eso va en una transacción, y por eso el pivote se sincroniza SIN desatar:
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

        $source = SnkKilkanaTagModel::query()->find($id);
        if ($source === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // `Rule::notIn` y no `different:{$id}` interpolado: la cadena de reglas se parte por `|`,
        // así que un id con esa barra —y la ruta lo acepta— se leería como una regla más y
        // devolvería un 500 con `Validator::validateX does not exist` dentro. La regla es la misma; lo que
        // cambia es que el valor viaja como valor.
        $data = $request->validate([
            'into' => ['required', 'integer', Rule::notIn([$source->id]), 'exists:kilkana_tags,id'],
        ]);

        $target = SnkKilkanaTagModel::query()->findOrFail($data['into']);
        $posts = $source->posts()->pluck('kilkana_posts.id')->all();

        // Callado: fundir borra una etiqueta, y «borró la etiqueta» no es lo que pasó.
        $activityLog = app(SnkKilkanaActivityService::class);
        $activityLog->quiet(fn () => DB::transaction(function () use ($source, $target, $posts) {
            $target->posts()->syncWithoutDetaching($posts);
            $source->delete();
        }));
        $activityLog->log('tag', 'tags_merged', $target->name, '/tags', ['before' => $source->name, 'after' => $target->name]);

        return response()->json(['id' => (string) $target->id, 'moved' => count($posts)]);
    }

    /**
     * Borra y DICE a cuántas piezas ha desetiquetado.
     *
     * El pivote se va por CASCADE sin preguntar, así que sin esta cifra «he borrado una etiqueta
     * que no usaba nadie» y «acabo de desetiquetar trescientos artículos» se verían igual desde
     * fuera. La pantalla borra sin confirmar —lo que se pierde es una palabra, no las piezas—, y
     * esta cifra es lo único que permite contarlo después.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $tag = SnkKilkanaTagModel::query()->find($id);
        if ($tag === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $posts = $tag->posts()->count();
        $tag->delete();

        return response()->json(['id' => (string) $id, 'detached' => $posts]);
    }
}
