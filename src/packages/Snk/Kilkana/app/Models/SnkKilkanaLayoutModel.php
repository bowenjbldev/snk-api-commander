<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Snk\Kilkana\Enums\SnkKilkanaLayoutEnum;

class SnkKilkanaLayoutModel extends Model
{
    protected $table = 'kilkana_layouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'layout' => SnkKilkanaLayoutEnum::class,
            // LayoutSchema completo; la validacion estructural profunda vive en el cliente (normalizeLayoutSchema).
            'schema' => 'array',
            'version' => 'integer',
        ];
    }
}
