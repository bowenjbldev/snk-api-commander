<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;

/**
 * El tema vendible del catálogo.
 *
 * Nació como puntero a una familia declarada en el frontend, cuando el catálogo eran dos
 * variantes. Ahora es el producto: se prepara, se publica, se navega y se retira, y lo que hace
 * distinta a cada variante viaja entero en su `spec`.
 *
 * Las filas las escribe `kilkana:sync-themes` desde los ficheros maestros del repo. No hay alta
 * por API: un tema es material del producto, no contenido de la redacción.
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
            'featured' => 'boolean',
            'screenshots' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SnkKilkanaThemeVariantModel::class, 'theme_id');
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
