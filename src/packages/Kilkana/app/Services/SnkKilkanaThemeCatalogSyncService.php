<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaThemeCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;

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
 * 2. **Jamás se toca el vestido de un periódico.** Lo que se lleva puesto es una copia
 *    congelada en `kilkana_settings`, no una fila de esta tabla: eso es exactamente lo que
 *    impide que publicar una versión nueva le cambie la portada a alguien que está en
 *    producción. Aquí se reescribe el catálogo entero sin mirar a quién viste qué.
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

        $spec = is_array($datos['spec'] ?? null) ? $datos['spec'] : [];
        if ($spec === []) {
            $resumen['errors'][] = "{$fichero}: un tema sin spec no se puede vestir";

            return;
        }

        // Validar ANTES de escribir nada: publicar la mitad de un tema deja el catálogo en un
        // estado que nadie pidió.
        $problemas = [];
        foreach ($this->validator->validate($spec) as $error) {
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

        DB::transaction(function () use ($datos, $spec, $categorias, $estado, $publicadoEn, &$resumen) {
            $tema = SnkKilkanaThemeModel::updateOrCreate(['slug' => $datos['slug']], [
                'name' => $datos['name'] ?? $datos['slug'],
                'tagline' => $datos['tagline'] ?? null,
                'description' => $datos['description'] ?? null,
                'status' => $estado,
                'spec' => $spec,
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
