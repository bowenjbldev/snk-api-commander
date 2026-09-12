<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Kilkana\Enums\SnkKilkanaLayoutEnum;

class SnkKilkanaLayoutModel extends Model
{
    protected $table = 'kilkana_layouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'layout' => SnkKilkanaLayoutEnum::class,
            // El documento del builder entero; la validacion estructural profunda vive en el cliente.
            'schema' => 'array',
            'version' => 'integer',
        ];
    }
}
