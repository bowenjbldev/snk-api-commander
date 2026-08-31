<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `core:seed` apunta la conexion `tenant` a la base del cliente, y tiene que devolverla.
 *
 * La conexion es del PROCESO, no del comando: dejarla puesta hace que lo siguiente que corra
 * ahi —otro job del worker, un `db:seed` a pelo— herede el destino de esta siembra sin haberlo
 * pedido. Y el destino de una siembra es en que periodico se escribe.
 */
class CoreSeedCommandTest extends TestCase
{
    public function test_devuelve_la_conexion_tenant_como_estaba(): void
    {
        $antes = config('database.connections.tenant.database');
        CoreSeedNoopStub::$baseDurante = null;

        $this->artisan('core:seed', [
            '--client' => 'demo',
            '--package' => 'Kilkana',
            '--class' => CoreSeedNoopStub::class,
            '--force' => true,
        ])->assertSuccessful();

        // Las dos aserciones son el par y ninguna vale sola: sin la primera, esto pasaria igual
        // con un comando que nunca hubiera configurado nada, que es la forma tonta de tener un
        // `finally` verde sobre un `try` que no hace nada.
        $this->assertSame('demo_kilkana', CoreSeedNoopStub::$baseDurante, 'Durante la siembra apunta al inquilino.');
        $this->assertSame($antes, config('database.connections.tenant.database'));
    }
}
