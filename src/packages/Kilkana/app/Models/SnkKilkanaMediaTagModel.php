<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Etiqueta de la biblioteca: describe el FICHERO («apaisado», «primer plano»), no el tema de
// la noticia. Vocabulario controlado, ver `docs/05-medios.md`.
class SnkKilkanaMediaTagModel extends Model
{
    protected $table = 'kilkana_media_tags';

    protected $fillable = ['name'];

    /** Los medios etiquetados. De aquí sale el recuento que se enseña antes de borrar. */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(
            SnkKilkanaMediaModel::class,
            'kilkana_media_media_tag',
            'media_tag_id',
            'media_id',
        );
    }
}
