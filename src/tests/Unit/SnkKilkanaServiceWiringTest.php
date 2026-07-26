<?php

namespace Tests\Unit;

use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaMediaController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaUsersController;
use Tests\TestCase;

class SnkKilkanaServiceWiringTest extends TestCase
{
    public function test_controllers_resolve_their_services(): void
    {
        foreach ([
            SnkKilkanaCommentsController::class,
            SnkKilkanaLayoutsController::class,
            SnkKilkanaMediaController::class,
            SnkKilkanaPostsController::class,
            SnkKilkanaPublicController::class,
            SnkKilkanaSettingsController::class,
            SnkKilkanaUsersController::class,
        ] as $controller) {
            $this->assertInstanceOf($controller, app($controller));
        }
    }
}
