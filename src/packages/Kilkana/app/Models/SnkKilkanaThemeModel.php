<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;

/**
 * El tema vendible del catálogo, y **un tema es un `spec`**.
 *
 * No hay variantes: si dos pieles no se reconocen como el mismo periódico son dos temas, y si
 * se reconocen, lo que las separa —densidad, acento, tamaño del titular— es lo que ajusta el
 * inquilino sobre el que ya lleva puesto, y eso vive en `kilkana_settings`.
 *
 * Las filas las escribe `kilkana:sync-themes` desde los ficheros maestros del repo. No hay alta
 * por API: un tema es material del producto, no contenido de la redacción. Esta tabla es de
 * SOLO LECTURA para el inquilino — lo suyo es la copia congelada de sus ajustes.
 */
class SnkKilkanaThemeModel extends Model
{
    protected $table = 'kilkana_themes';

    protected $guarded = [];

    /** Los criterios de orden del catálogo. Cada uno responde una pregunta distinta. */
    public const SORTS = ['featured', 'recent', 'popular', 'name'];

    protected function casts(): array
    {
        return [
            'status' => SnkKilkanaThemeStatusEnum::class,
            'spec' => 'array',
            'featured' => 'boolean',
            'screenshots' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            SnkKilkanaThemeCategoryModel::class,
            'kilkana_theme_category_theme',
            'theme_id',
            'theme_category_id'
        );
    }

    /** Lo único que ve quien elige tema: ni los que se están preparando ni los retirados. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SnkKilkanaThemeStatusEnum::Published);
    }

    /**
     * El buscador del catálogo, sobre el índice FULLTEXT de la tabla.
     *
     * En modo booleano y con comodín al final para que teclear «tec» ya encuentre los temas de
     * tecnología: un buscador de catálogo se usa escribiendo, no pulsando enter.
     */
    public function scopeSearch(Builder $query, string $termino): Builder
    {
        $limpio = trim(preg_replace('/[+\-><()~*"@]+/', ' ', $termino) ?? '');

        return $limpio === ''
            ? $query
            : $query->whereFullText(['name', 'tagline', 'description'], $limpio.'*', ['mode' => 'boolean']);
    }

    /**
     * Lo que sale a la API: la ficha del catálogo con su tema dentro.
     *
     * El `spec` viaja entero y no por partes. Quien pinta recibe el tema y lo emite; que la
     * galería enseñe exactamente lo que se va a llevar puesto es la mitad de para qué existe
     * una galería.
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'status' => $this->status?->value,
            'featured' => (bool) $this->featured,
            'position' => $this->position,
            'popularity' => $this->popularity,
            'version' => $this->version,
            'thumbnail_path' => $this->thumbnail_path,
            'screenshots' => $this->screenshots ?? [],
            'demo_url' => $this->demo_url,
            'spec' => (object) ($this->spec ?? []),
        ];
    }

    /**
     * Cuatro órdenes, y ninguno se deriva de los otros.
     *
     * `featured` es la parrilla curada —lo que quieres enseñar primero—, `recent` la de
     * novedades, `popular` la que ordena por el número que fijas al publicar y `name` la
     * alfabética de quien ya sabe lo que busca.
     */
    public function scopeSorted(Builder $query, ?string $criterio): Builder
    {
        return match ($criterio) {
            'recent' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'popular' => $query->orderByDesc('popularity')->orderBy('position'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->orderBy('position')->orderBy('name'),
        };
    }
}
