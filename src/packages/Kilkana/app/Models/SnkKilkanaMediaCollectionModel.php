<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Colección de la biblioteca: agrupa medios por trabajo o por fecha. Ver `docs/products/kilkana/database/05-medios.md`.
class SnkKilkanaMediaCollectionModel extends Model
{
    protected $table = 'kilkana_media_collections';

    protected $fillable = ['name'];

    /** Los medios de la colección. De aquí sale el recuento que se enseña antes de borrar. */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(
            SnkKilkanaMediaModel::class,
            'kilkana_media_collection_media',
            'media_collection_id',
            'media_id',
        );
    }
}
