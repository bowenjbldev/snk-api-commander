<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Clave-valor por post (estilo wp_postmeta); el controller valida las claves admitidas.
class SnkKilkanaPostMetaModel extends Model
{
    protected $table = 'kilkana_post_meta';

    protected $guarded = [];
}
