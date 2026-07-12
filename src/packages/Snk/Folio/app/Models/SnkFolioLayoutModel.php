<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;

class SnkFolioLayoutModel extends Model
{
    protected $table = 'folio_layouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // LayoutSchema completo; la validacion estructural profunda vive en el cliente (normalizeLayoutSchema).
            'schema' => 'array',
            'version' => 'integer',
        ];
    }
}
