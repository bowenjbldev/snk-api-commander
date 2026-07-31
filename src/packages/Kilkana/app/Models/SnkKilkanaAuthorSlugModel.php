<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// URLs retiradas de una firma: la vieja redirige a la ficha actual. Ver `docs/02-firmas.md`.
class SnkKilkanaAuthorSlugModel extends Model
{
    protected $table = 'kilkana_author_slugs';

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaAuthorModel::class, 'author_id');
    }
}
