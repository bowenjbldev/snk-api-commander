<?php

namespace Tests;

use Illuminate\Support\Facades\URL;

/**
 * El Commander corre en el deploy centralizado bajo su subdominio (commander.{BASE_DOMAIN}).
 * Las peticiones de test deben llegar con ese host para casar con Route::domain(...).
 */
abstract class CommanderTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://commander.snakepc.test']);
        URL::forceRootUrl('http://commander.snakepc.test');
    }
}
