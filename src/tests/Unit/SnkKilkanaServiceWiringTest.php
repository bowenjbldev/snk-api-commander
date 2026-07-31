<?php

namespace Tests\Unit;

use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaUsersController;
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
