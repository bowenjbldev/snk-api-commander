<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaThemeCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Services\SnkKilkanaThemeWearService;

/**
 * Los temas del periódico: **el catálogo se lee, y solo se elige uno**.
 *
 * `GET themes` devuelve la galería entera en una llamada, con el `spec` de cada tema dentro:
 * elegir se hace mirando la portada dibujada, así que quien pinta necesita el tema, no una
 * ficha del tema.
 *
 * No hay CRUD. Un tema es material del producto —lo escribe `kilkana:sync-themes` desde los
 * ficheros maestros— y lo único que un periódico hace con él es vestírselo, que copia su spec
 * a los ajustes. Lo que la redacción retoca encima se guarda ahí, en `PATCH settings`.
 */
#[Group('Themes')]
class SnkKilkanaThemesController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaThemeModel::class;

    protected string $indexPermission = 'index themes';

    protected string $showPermission = 'show themes';

    protected string $updatePermission = 'update themes';

    public function __construct(private readonly SnkKilkanaThemeWearService $wear) {}

    /**
     * El catálogo: temas publicados, filtrables y ordenables, con su spec dentro.
     *
     * Un solo endpoint devuelve la galería entera. El filtro y el orden viajan como query para
     * que la URL de una búsqueda sea compartible.
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
            ->with('categories')
            ->when(
                $filtros['category'] ?? null,
                fn ($q, $slug) => $q->whereHas('categories', fn ($c) => $c->where('slug', $slug))
            )
            ->when($filtros['q'] ?? null, fn ($q, $termino) => $q->search($termino))
            ->sorted($filtros['sort'] ?? null)
            ->get()
            ->map(fn (SnkKilkanaThemeModel $tema) => [
                ...$tema->payload(),
                'published_at' => $tema->published_at?->toIso8601String(),
                'categories' => $tema->categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
            ]);

        return response()->json(['themes' => $catalogo]);
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
     * Vestir un tema del catálogo: se copia su spec y se viste la copia.
     *
     * Lo que no puede pasar es que los ajustes apunten al spec de la fila del catálogo: la
     * próxima sincronización la reescribiría y el periódico cambiaría de aspecto sin pedirlo.
     * Los retoques anteriores se vacían — eran del tema anterior.
     */
    public function wear($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $tema = $this->findModel($id);

        if ($tema === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $ajustes = $this->wear->wear($tema);
        $ajustes->load('theme');

        return response()->json($ajustes->themePayload());
    }
}
