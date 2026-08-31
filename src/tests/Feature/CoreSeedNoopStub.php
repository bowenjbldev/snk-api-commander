<?php

namespace Tests\Feature;

use Illuminate\Database\Seeder;

/** Un seeder que no consulta nada: anota a que base apuntaba la conexion mientras corria. */
class CoreSeedNoopStub extends Seeder
{
    public static ?string $baseDurante = null;

    public function run(): void
    {
        self::$baseDurante = config('database.connections.tenant.database');
    }
}
