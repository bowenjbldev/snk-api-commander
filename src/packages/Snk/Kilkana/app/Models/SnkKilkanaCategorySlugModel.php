<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// URLs retiradas de una sección: la vieja redirige a la actual. Ver `docs/03-secciones.md`.
class SnkKilkanaCategorySlugModel extends Model
{
    protected $table = 'kilkana_category_slugs';

    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaCategoryModel::class, 'category_id');
    }
}
