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
    public function sync(?string $directory = null): array
    {
        $directory ??= base_path('packages/Kilkana/database/seeders/data/themes');
        $summary = ['written' => 0, 'skipped' => 0, 'categories' => 0, 'errors' => []];

        if (! is_dir($directory)) {
            $summary['errors'][] = "Themes directory does not exist: {$directory}";

            return $summary;
        }

        $summary['categories'] = $this->syncCategories($directory, $summary);

        // Los ficheros con guion bajo delante son vocabulario, no temas.
        foreach (glob($directory.'/*.json') as $path) {
            if (str_starts_with(basename($path), '_')) {
                continue;
            }

            $this->syncTheme($path, $summary);
        }

        return $summary;
    }

    private function syncCategories(string $directory, array &$summary): int
    {
        $path = $directory.'/_categories.json';
        if (! is_file($path)) {
            return 0;
        }

        $categories = $this->readJson($path, $summary);
        if (! is_array($categories)) {
            return 0;
        }

        foreach ($categories as $category) {
            if (! isset($category['slug'], $category['name'])) {
                $summary['errors'][] = '_categories.json: an entry without slug or name';

                continue;
            }

            SnkKilkanaThemeCategoryModel::updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'position' => $category['position'] ?? 0]
            );
        }

        return count($categories);
    }

    private function syncTheme(string $path, array &$summary): void
    {
        $file = basename($path);
        $data = $this->readJson($path, $summary);
        if (! is_array($data)) {
            return;
        }

        if (! isset($data['slug'], $data['version'])) {
            $summary['errors'][] = "{$file}: missing slug or version";

            return;
        }

        $spec = is_array($data['spec'] ?? null) ? $data['spec'] : [];
        if ($spec === []) {
            $summary['errors'][] = "{$file}: a theme without a spec cannot be worn";

            return;
        }

        // Validar ANTES de escribir nada: publicar la mitad de un tema deja el catálogo en un
        // estado que nadie pidió.
        $problems = [];
        foreach ($this->validator->validate($spec) as $error) {
            $problems[] = "{$file}: {$error}";
        }

        $categories = $this->resolveCategories($data['categories'] ?? [], $file, $problems);

        if ($problems !== []) {
            $summary['errors'] = [...$summary['errors'], ...$problems];

            return;
        }

        $existing = SnkKilkanaThemeModel::query()->where('slug', $data['slug'])->first();
        if ($existing && $existing->version === $data['version']) {
            $summary['skipped']++;

            return;
        }

        $status = $data['status'] ?? SnkKilkanaThemeStatusEnum::Draft->value;
        // Se sella la primera vez que sale publicado y no se recalcula: es la fecha por la que
        // ordena «novedades», y republicar una corrección no vuelve nuevo un tema de hace un año.
        $publishedAt = $status === SnkKilkanaThemeStatusEnum::Published->value
            ? ($existing?->published_at ?? now())
            : null;

        DB::transaction(function () use ($data, $spec, $categories, $status, $publishedAt, &$summary) {
            $theme = SnkKilkanaThemeModel::updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'] ?? $data['slug'],
                'tagline' => $data['tagline'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $status,
                'spec' => $spec,
                'featured' => (bool) ($data['featured'] ?? false),
                'position' => $data['position'] ?? 0,
                'popularity' => $data['popularity'] ?? 0,
                'version' => $data['version'],
                'thumbnail_path' => $data['thumbnail_path'] ?? null,
                'screenshots' => $data['screenshots'] ?? null,
                'demo_url' => $data['demo_url'] ?? null,
                'published_at' => $publishedAt,
            ]);

            $theme->categories()->sync($categories);

            $summary['written']++;
        });
    }

    /** Una categoría que no está en el vocabulario es un error, no una categoría nueva. */
    private function resolveCategories(mixed $slugs, string $file, array &$problems): array
    {
        $ids = [];

        foreach ((array) $slugs as $slug) {
            $id = SnkKilkanaThemeCategoryModel::query()->where('slug', $slug)->value('id');
            if ($id === null) {
                $problems[] = "{$file}: category «{$slug}» is not in _categories.json";

                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function readJson(string $path, array &$summary): mixed
    {
        $data = json_decode((string) file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $summary['errors'][] = basename($path).': invalid JSON — '.json_last_error_msg();

            return null;
        }

        return $data;
    }
}
