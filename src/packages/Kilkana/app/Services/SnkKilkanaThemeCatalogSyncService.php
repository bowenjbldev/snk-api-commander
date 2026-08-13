<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaThemeCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Models\SnkKilkanaThemeVariantModel;

/**
 * Lleva el catálogo de los ficheros maestros a la base de un periódico.
 *
 * El maestro son ficheros versionados en git, no una tabla: un tema pasa por revisión como
 * cualquier otro cambio y se revierte igual. La base de cada inquilino es una COPIA, y por eso
 * hace falta este paso — el catálogo no se comparte entre periódicos.
 *
 * Dos reglas que sostienen todo lo demás:
 *
 * 1. **Se compara por `version`.** Un catálogo de trescientos temas sin cambios no escribe una
 *    sola fila. Publicar uno escribe ese.
 * 2. **Jamás se toca una fila `tenant`.** Lo que un periódico adoptó es suyo y está congelado:
 *    esa copia es exactamente lo que impide que publicar una versión nueva le cambie la portada
 *    a alguien que está en producción.
 */
class SnkKilkanaThemeCatalogSyncService
{
    public function __construct(private readonly SnkKilkanaThemeSpecValidator $validator) {}

    /**
     * @return array{written: int, skipped: int, categories: int, errors: list<string>}
     */
    public function sync(?string $directorio = null): array
    {
        $directorio ??= base_path('packages/Kilkana/database/seeders/data/themes');
        $resumen = ['written' => 0, 'skipped' => 0, 'categories' => 0, 'errors' => []];

        if (! is_dir($directorio)) {
            $resumen['errors'][] = "No existe el directorio de temas: {$directorio}";

            return $resumen;
        }

        $resumen['categories'] = $this->syncCategories($directorio, $resumen);

        // Los ficheros con guion bajo delante son vocabulario, no temas.
        foreach (glob($directorio.'/*.json') as $ruta) {
            if (str_starts_with(basename($ruta), '_')) {
                continue;
            }

            $this->syncTheme($ruta, $resumen);
        }

        return $resumen;
    }

    private function syncCategories(string $directorio, array &$resumen): int
    {
        $ruta = $directorio.'/_categories.json';
        if (! is_file($ruta)) {
            return 0;
        }

        $categorias = $this->readJson($ruta, $resumen);
        if (! is_array($categorias)) {
            return 0;
        }

        foreach ($categorias as $categoria) {
            if (! isset($categoria['slug'], $categoria['name'])) {
                $resumen['errors'][] = '_categories.json: una entrada sin slug o sin name';

                continue;
            }

            SnkKilkanaThemeCategoryModel::updateOrCreate(
                ['slug' => $categoria['slug']],
                ['name' => $categoria['name'], 'position' => $categoria['position'] ?? 0]
            );
        }

        return count($categorias);
    }

    private function syncTheme(string $ruta, array &$resumen): void
    {
        $fichero = basename($ruta);
        $datos = $this->readJson($ruta, $resumen);
        if (! is_array($datos)) {
            return;
        }

        if (! isset($datos['slug'], $datos['version'])) {
            $resumen['errors'][] = "{$fichero}: falta slug o version";

            return;
        }

        $variantes = is_array($datos['variants'] ?? null) ? $datos['variants'] : [];
        if ($variantes === []) {
            $resumen['errors'][] = "{$fichero}: un tema sin variantes no se puede vestir";

            return;
        }

        // Validar ANTES de escribir nada, y el tema entero: publicar la mitad de un tema deja
        // el catálogo en un estado que nadie pidió.
        $problemas = [];
        foreach ($variantes as $variante) {
            $slug = $variante['slug'] ?? '?';
            foreach ($this->validator->validate(is_array($variante['spec'] ?? null) ? $variante['spec'] : []) as $error) {
                $problemas[] = "{$fichero} [{$slug}] {$error}";
            }
        }

        // Y que las variantes sean el mismo periódico. El `spec` es autosuficiente —copiarlo lo
        // dibuja— y el precio de esa autonomía es que la rejilla y las tipografías se repiten en
        // cada una. Repetido y sin comprobar sería una promesa rota: una variante con otro ancho
        // de hoja ya no es la misma cabecera, y entonces no son dos pieles, son dos temas.
        foreach ($this->validator->validateFamily($variantes) as $error) {
            $problemas[] = "{$fichero}: {$error}";
        }

        $categorias = $this->resolveCategories($datos['categories'] ?? [], $fichero, $problemas);

        if ($problemas !== []) {
            $resumen['errors'] = [...$resumen['errors'], ...$problemas];

            return;
        }

        $existente = SnkKilkanaThemeModel::query()->where('slug', $datos['slug'])->first();
        if ($existente && $existente->version === $datos['version']) {
            $resumen['skipped']++;

            return;
        }

        $estado = $datos['status'] ?? SnkKilkanaThemeStatusEnum::Draft->value;
        // Se sella la primera vez que sale publicado y no se recalcula: es la fecha por la que
        // ordena «novedades», y republicar una corrección no vuelve nuevo un tema de hace un año.
        $publicadoEn = $estado === SnkKilkanaThemeStatusEnum::Published->value
            ? ($existente?->published_at ?? now())
            : null;

        DB::transaction(function () use ($datos, $variantes, $categorias, $estado, $publicadoEn, &$resumen) {
            $tema = SnkKilkanaThemeModel::updateOrCreate(['slug' => $datos['slug']], [
                'name' => $datos['name'] ?? $datos['slug'],
                'tagline' => $datos['tagline'] ?? null,
                'description' => $datos['description'] ?? null,
                'status' => $estado,
                'featured' => (bool) ($datos['featured'] ?? false),
                'position' => $datos['position'] ?? 0,
                'popularity' => $datos['popularity'] ?? 0,
                'version' => $datos['version'],
                'thumbnail_path' => $datos['thumbnail_path'] ?? null,
                'screenshots' => $datos['screenshots'] ?? null,
                'demo_url' => $datos['demo_url'] ?? null,
                'published_at' => $publicadoEn,
            ]);

            $tema->categories()->sync($categorias);

            $vistos = [];
            foreach ($variantes as $orden => $variante) {
                $vistos[] = $variante['slug'];
                SnkKilkanaThemeVariantModel::updateOrCreate(
                    ['slug' => $variante['slug']],
                    [
                        'theme_id' => $tema->id,
                        'source' => SnkKilkanaThemeSourceEnum::Catalog->value,
                        'name' => $variante['name'] ?? null,
                        'spec' => $variante['spec'],
                        'position' => $variante['position'] ?? $orden,
                        'is_default' => (bool) ($variante['is_default'] ?? false),
                        'thumbnail_path' => $variante['thumbnail_path'] ?? null,
                    ]
                );
            }

            // Una variante retirada del fichero desaparece del catálogo, pero solo del catálogo:
            // el filtro por `source` es lo que protege la copia que alguien esté vistiendo.
            SnkKilkanaThemeVariantModel::query()
                ->where('theme_id', $tema->id)
                ->where('source', SnkKilkanaThemeSourceEnum::Catalog->value)
                ->whereNotIn('slug', $vistos)
                ->delete();

            $resumen['written']++;
        });
    }

    /** Una categoría que no está en el vocabulario es un error, no una categoría nueva. */
    private function resolveCategories(mixed $slugs, string $fichero, array &$problemas): array
    {
        $ids = [];

        foreach ((array) $slugs as $slug) {
            $id = SnkKilkanaThemeCategoryModel::query()->where('slug', $slug)->value('id');
            if ($id === null) {
                $problemas[] = "{$fichero}: la categoría «{$slug}» no está en _categories.json";

                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function readJson(string $ruta, array &$resumen): mixed
    {
        $datos = json_decode((string) file_get_contents($ruta), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $resumen['errors'][] = basename($ruta).': JSON inválido — '.json_last_error_msg();

            return null;
        }

        return $datos;
    }
}
