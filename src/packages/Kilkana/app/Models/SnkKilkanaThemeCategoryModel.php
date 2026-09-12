<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Con qué se navega el catálogo: tecnología, anime, deportes, revista…
 *
 * Vocabulario cerrado que llega del fichero maestro. No hay alta por API a propósito: una
 * categoría que se puede crear al vuelo deja de ser un filtro y se convierte en la lista de
 * sinónimos de quien la escribió.
 */
class SnkKilkanaThemeCategoryModel extends Model
{
    protected $table = 'kilkana_theme_categories';

    protected $guarded = [];

    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(
            SnkKilkanaThemeModel::class,
            'kilkana_theme_category_theme',
            'theme_category_id',
            'theme_id'
        );
    }
}
