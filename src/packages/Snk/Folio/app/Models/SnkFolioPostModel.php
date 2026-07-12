<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;

// Sin $connection: el resolutor de host ya conmuta la conexion default a la BD del par (snk, folio).
class SnkFolioPostModel extends Model
{
    protected $table = 'folio_posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'body_blocks' => 'array',
            'tags' => 'array',
            'featured' => 'boolean',
            'published_at' => 'datetime',
            'reads' => 'integer',
        ];
    }
}
