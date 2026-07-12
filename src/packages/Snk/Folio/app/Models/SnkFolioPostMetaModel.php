<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Clave-valor por post (estilo wp_postmeta); el controller valida las claves admitidas.
class SnkFolioPostMetaModel extends Model
{
    protected $table = 'folio_post_meta';

    protected $guarded = [];
}
