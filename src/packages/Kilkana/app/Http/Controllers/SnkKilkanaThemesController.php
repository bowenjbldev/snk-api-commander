<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaThemeCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Models\SnkKilkanaThemeVariantModel;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;
use Packages\Kilkana\Services\SnkKilkanaSettingService;
use Packages\Kilkana\Services\SnkKilkanaThemeAdoptionService;

/**
 * Los temas del periódico: paquetes para leer, variantes para escribir.
 *
 * `GET themes` devuelve los paquetes con sus variantes dentro porque es lo que necesita la
 * galería en una sola llamada, y el store saca su lista plana de ahí igual que hacía con
 * `THEME_PACKS.flatMap`. Lo que se crea, edita y borra es siempre una VARIANTE, así que el
 * CRUD cuelga de `theme-variants` y no comparte nombre con la lectura: un paquete no se edita
 * desde aquí, lo envía el producto.
 */
#[Group('Themes')]
class SnkKilkanaThemesController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaThemeVariantModel::class;

    protected string $indexPermission = 'index themes';

    protected string $showPermission = 'show themes';

    protected string $storePermission = 'create themes';

    protected string $updatePermission = 'update themes';

    protected string $destroyPermission = 'delete themes';

    public function __construct(
        private readonly SnkKilkanaThemeAdoptionService $adoptions,
        private readonly SnkKilkanaSettingService $settings,
    ) {
        // Las reglas de forma llevan closures (los rangos con unidad), y un inicializador de
        // propiedad en PHP solo admite expresiones constantes. Por eso se componen aquí.
        //
        // Solo se editan las variantes PROPIAS, y solo su nombre y su retoque: el spec lo trae
        // el catálogo y cambiarlo por API rompería la copia congelada.
        $this->validationRulesUpdate = [
            'name' => 'sometimes|required|string|max:255',
            ...SnkKilkanaThemeVariantModel::shapeRules(),
        ];
    }

    /**
     * El catálogo: temas publicados, filtrables y ordenables, con sus variantes dentro.
     *
     * Un solo endpoint para la galería entera porque elegir tema es una pantalla, no cinco. El
     * filtro y el orden viajan como query para que la URL de una búsqueda sea compartible.
     */
    public function catalog(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $filtros = $request->validate([
            'category' => 'nullable|string|max:64',
            'q' => 'nullable|string|max:120',
            'sort' => 'nullable|string|in:'.implode(',', SnkKilkanaThemeModel::SORTS),
        ]);

        $catalogo = SnkKilkanaThemeModel::query()
            ->published()
            ->with(['categories', 'variants' => fn ($q) => $q->fromCatalog()->orderBy('position')])
            ->when(
                $filtros['category'] ?? null,
                fn ($q, $slug) => $q->whereHas('categories', fn ($c) => $c->where('slug', $slug))
            )
            ->when($filtros['q'] ?? null, fn ($q, $termino) => $q->search($termino))
            ->sorted($filtros['sort'] ?? null)
            ->get()
            ->map(fn (SnkKilkanaThemeModel $tema) => $this->themePayload($tema));

        // Lo que este periódico ha hecho suyo va aparte: no es catálogo y no se filtra por
        // categoría, pero la galería lo tiene que enseñar o una variante propia sería invisible.
        $propias = SnkKilkanaThemeVariantModel::query()
            ->owned()
            // El aviso de «hay versión nueva» compara contra el tema del que salió la copia, y
            // el lazy loading está deshabilitado a propósito: sin esto son dos consultas por fila.
            ->with('origin.theme')
            ->orderBy('position')
            ->get()
            ->map(fn (SnkKilkanaThemeVariantModel $variante) => [
                ...$variante->payload(),
                'update_available' => $this->adoptions->updateAvailable($variante),
            ]);

        return response()->json(['themes' => $catalogo, 'owned' => $propias]);
    }

    /** El vocabulario con el que se filtra. Se lee; no se crea desde aquí. */
    public function categories(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        return response()->json(
            SnkKilkanaThemeCategoryModel::query()->orderBy('position')->orderBy('name')->get(['id', 'slug', 'name'])
        );
    }

    /**
     * Vestir un tema del catálogo: se copia y se viste la copia.
     *
     * Adoptar y vestir son el mismo gesto para quien elige, así que son una sola llamada. Lo
     * que no puede pasar es apuntar los ajustes a una fila del catálogo: la próxima
     * sincronización la reescribiría y el periódico cambiaría de aspecto sin pedirlo.
     */
    public function adopt($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $variante = $this->findModel($id);

        if ($variante === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $propia = $this->adoptions->adopt($variante);

        $ajustes = $this->settings->get();
        $ajustes->theme_variant_id = $propia->id;
        $ajustes->save();

        return response()->json($propia->payload());
    }

    private function themePayload(SnkKilkanaThemeModel $tema): array
    {
        return [
            'id' => $tema->id,
            'slug' => $tema->slug,
            'name' => $tema->name,
            'tagline' => $tema->tagline,
            'description' => $tema->description,
            'featured' => (bool) $tema->featured,
            'popularity' => (int) $tema->popularity,
            'version' => $tema->version,
            'thumbnail_path' => $tema->thumbnail_path,
            'screenshots' => $tema->screenshots ?? [],
            'demo_url' => $tema->demo_url,
            'published_at' => $tema->published_at?->toIso8601String(),
            'categories' => $tema->categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
            'variants' => $tema->variants->map(fn (SnkKilkanaThemeVariantModel $v) => $v->payload())->values(),
        ];
    }

    /**
     * Borrar una variante, diciendo qué lo impide antes de intentarlo.
     *
     * Las dos comprobaciones van por delante y no se descubren por el error de la base: una
     * builtin es la base de la que cuelgan las demás, y la que viste el periódico está
     * protegida por un RESTRICT que respondería un error de motor en vez de algo accionable.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        $variante = $this->findModel($id);

        if ($variante === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        if ($variante->source !== SnkKilkanaThemeSourceEnum::Tenant) {
            return response()->json(['error' => 'A catalog variant cannot be deleted; it is published by the product.'], 422);
        }

        if (SnkKilkanaSettingModel::query()->where('theme_variant_id', $variante->id)->exists()) {
            return response()->json([
                'error' => 'That variant is the one the newspaper is wearing; switch to another first.',
            ], 422);
        }

        $variante->delete();

        return response()->json(['id' => $id]);
    }
}
