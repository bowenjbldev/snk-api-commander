<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `core:seed` apunta la conexión `tenant` a la base del cliente, y tiene que devolverla.
 *
 * La conexión es del PROCESO, no del comando: dejarla puesta hace que lo siguiente que corra
 * ahí —otro job del worker, un `db:seed` a pelo— herede el destino de esta siembra sin haberlo
 * pedido. Y el destino de una siembra es en qué periódico se escribe.
 */
class CoreSeedCommandTest extends TestCase
{
    public function test_restores_the_tenant_connection_as_it_was(): void
    {
        $before = config('database.connections.tenant.database');
        CoreSeedNoopStub::$databaseDuringSeed = null;

        $this->artisan('core:seed', [
            '--client' => 'demo',
            '--package' => 'Kilkana',
            '--class' => CoreSeedNoopStub::class,
            '--force' => true,
        ])->assertSuccessful();

        // Las dos aserciones son el par y ninguna vale sola: sin la primera, esto pasaría igual
        // con un comando que nunca configura nada, que es la forma tonta de tener un
        // `finally` verde sobre un `try` que no hace nada.
        $this->assertSame('demo_kilkana', CoreSeedNoopStub::$databaseDuringSeed, 'Durante la siembra apunta al inquilino.');
        $this->assertSame($before, config('database.connections.tenant.database'));
    }
}
