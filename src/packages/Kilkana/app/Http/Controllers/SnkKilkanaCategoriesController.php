<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaArticleTemplateEnum;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaCategoryResource;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('Categories')]
class SnkKilkanaCategoriesController extends CoreBaseApiController
{
    /** `rule()` es una llamada, y una propiedad no se puede inicializar con eso: va aquí. */
    public function __construct()
    {
        foreach (['validationRulesStore', 'validationRulesUpdate'] as $property) {
            $this->{$property}['status'] = 'sometimes|'.SnkKilkanaCategoryStatusEnum::rule();
            // La plantilla de pieza de la sección. Nula es «no elige»: sigue a su madre o, sin
            // ella, a la de noticia.
            $this->{$property}['template'] = 'sometimes|nullable|'.SnkKilkanaArticleTemplateEnum::rule();
        }
    }

    protected string $modelClass = SnkKilkanaCategoryModel::class;

    protected ?string $resourceClass = SnkKilkanaCategoryResource::class;

    protected array $allowedFields = ['id', 'name', 'slug', 'status', 'position', 'parent_id', 'template', 'noindex', 'updated_at'];

    /**
     * Cada sección sale con cuántas piezas archiva, cuándo publicó la última y qué URLs dejó
     * atrás — y en el orden del menú.
     *
     * Las dos primeras son subconsultas por fila sobre un catálogo corto, y no un recuento por
     * petición: el panel enseña «Local 40 · 28 Jun», y sin esto la única forma de saberlo es
     * traerse el archivo entero al cliente y contarlo allí. `last_published_at` mira solo lo
     * publicado: un borrador de hoy no es actividad de la sección.
     *
     * El orden es `position` y no la clave: el menú del periódico es esta lista, y ordenar por
     * id devolvería el orden de creación en cuanto alguien reordene. `id` desempata, porque
     * `position` nace a cero en todas y sin segundo criterio el orden sería el que quisiera la
     * base.
     *
     * Va en `decorate()` y no en el modelo porque **solo lo quiere quien lista**: pedir una
     * sección para editarla no necesita contar sus piezas. Y detrás de `?fields=`, porque el
     * recorte hace `select()` y se llevaría los dos agregados.
     */
    protected function decorate(QueryBuilder $query): QueryBuilder
    {
        return $query->asCatalogue();
    }

    protected string $indexPermission = 'index categories';

    protected string $showPermission = 'show categories';

    protected string $storePermission = 'create categories';

    protected string $updatePermission = 'update categories';

    protected string $destroyPermission = 'delete categories';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        // Nulo aquí sí: es la señal de «derívalo del nombre», y lo hace el modelo.
        // Con la forma de un slug, o no es una URL que `[[section]]` vaya a casar nunca: ni puntos
        // —`robots.txt` es una ruta fija—, ni barras, ni mayúsculas. Derivado del nombre ya sale así.
        'slug' => 'nullable|string|max:'.SnkKilkanaCategoryModel::SLUG_MAX.'|regex:'.SnkKilkanaCategoryModel::SLUG_SHAPE.'|unique:kilkana_categories,slug',
        // Sin estado nace en preparación. El asistente las crea ya en el menú: la primera pieza
        // es el paso siguiente y una sección en preparación no admite piezas publicadas.
        'status' => 'sometimes|string|in:draft,live',
        'parent_id' => 'nullable|integer|exists:kilkana_categories,id',
        'description' => 'nullable|string',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'noindex' => 'sometimes|boolean',
        'position' => 'nullable|integer|min:0|max:65535',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        // Aquí NO puede ser nulo: la columna no lo admite y el derivador solo corre al crear,
        // así que un `slug: null` en una edición sería un 500. Una sección viva tiene URL
        // siempre; se puede cambiar, no vaciar.
        'slug' => 'sometimes|required|string|max:'.SnkKilkanaCategoryModel::SLUG_MAX.'|regex:'.SnkKilkanaCategoryModel::SLUG_SHAPE.'|unique:kilkana_categories,slug,{id}',
        // Primera línea contra el ciclo trivial. La forma del árbol —dos niveles y sin
        // ciclos— la impone `SnkKilkanaCategoryModel::guardTree`, que también cubre al
        // seeder y a la consola, que no pasan por aquí.
        'parent_id' => 'nullable|integer|exists:kilkana_categories,id|not_in:{id}',
        'description' => 'nullable|string',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'noindex' => 'sometimes|boolean',
        'position' => 'nullable|integer|min:0|max:65535',
    ];

    /**
     * Una sección que no dice su puesto **nace la última entre sus hermanas**. La columna nace a
     * cero, y a cero ya está la primera del menú: sin esto la nueva se cuela la segunda, por
     * delante de todas las que alguien ordenó. Quien manda el puesto —el asistente, la siembra—
     * lo conserva.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->has('position')) {
            $last = SnkKilkanaCategoryModel::query()->where('parent_id', $request->input('parent_id'))->max('position');
            $request->merge(['position' => $last === null ? 0 : $last + 1]);
        }

        return parent::store($request);
    }

    /**
     * **Cambiar la plantilla de pieza de una sección mueve su archivo entero**, porque las piezas
     * la siguen en vivo. Con `keep_posts` no: las que ya existen se quedan con la que llevaban,
     * fijada en cada una, y solo las que se escriban desde entonces llevan la nueva.
     *
     * Las dos escrituras van en una transacción: una sección cambiada con el archivo a medio
     * fijar no es ninguno de los dos resultados que se ofrecen.
     */
    public function update(Request $request, $id = null): JsonResponse
    {
        $section = $request->boolean('keep_posts') ? SnkKilkanaCategoryModel::query()->find($id) : null;
        if ($section === null) {
            return parent::update($request, $id);
        }

        $news = SnkKilkanaArticleTemplateEnum::News->value;
        $before = $section->inheritedTemplate() ?? $news;

        return $section->getConnection()->transaction(function () use ($request, $id, $section, $before, $news) {
            $response = parent::update($request, $id);
            if ($response->isSuccessful() && ($section->refresh()->inheritedTemplate() ?? $news) !== $before) {
                $section->pinPosts($before);
            }

            return $response;
        });
    }

    /**
     * Borrar dice QUÉ lo impide, en vez de reventar con el SQL a la vista.
     *
     * Dos claves foráneas protegen una sección —sus artículos y sus subsecciones, las dos
     * RESTRICT— y al saltar devolverían un 500 con el nombre de la tabla y de la restricción
     * dentro. Quien lo recibiera no podría distinguir «esta sección tiene contenido» de «el
     * servidor se ha roto», y de paso se publicaría el esquema.
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

        // Con `with_posts` la sección se lleva sus piezas, y eso es borrar piezas: pide también
        // ese permiso.
        $withPosts = request()->boolean('with_posts');
        if ($withPosts && ($response = $this->requirePermission('delete posts'))) {
            return $response;
        }

        $posts = SnkKilkanaPostModel::query()->where('category_id', $id)->count();
        $subsections = SnkKilkanaCategoryModel::query()->where('parent_id', $id)->count();

        if (($posts > 0 && ! $withPosts) || $subsections > 0) {
            return response()->json([
                'error' => 'Section in use',
                'posts' => $posts,
                'subsections' => $subsections,
            ], 409);
        }

        try {
            return SnkKilkanaCategoryModel::query()->getConnection()->transaction(function () use ($id) {
                // Lo que está en la papelera no se cuenta arriba, pero la clave foránea sí lo ve: se
                // purga con la sección, porque sin ella no tiene adónde volver. Con `with_posts`,
                // las vivas también.
                SnkKilkanaPostModel::withTrashed()->where('category_id', $id)->get()->each->forceDelete();

                return parent::destroy($id);
            });
        } catch (QueryException) {
            // Red de seguridad: entre el recuento y el borrado cabe una escritura, y mañana
            // puede haber una tercera clave foránea que este método no conozca. Nunca un 500
            // con SQL dentro.
            return response()->json(['error' => 'Section in use'], 409);
        }
    }
}
