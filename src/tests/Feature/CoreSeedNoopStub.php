<?php

namespace Tests\Feature;

use Illuminate\Database\Seeder;

/** Un seeder que no consulta nada: anota a que base apuntaba la conexion mientras corria. */
class CoreSeedNoopStub extends Seeder
{
    public static ?string $databaseDuringSeed = null;

    public function run(): void
    {
        self::$databaseDuringSeed = config('database.connections.tenant.database');
    }
}
