<?php

namespace Tests\Unit;

use Packages\Core\Support\CorePackageSelectorSupport;
use PHPUnit\Framework\TestCase;

class CorePackageSelectorSupportTest extends TestCase
{
    /**
     * La caché de permisos de un inquilino no puede ser la de otro.
     *
     * Spatie cachea el registro de permisos en una clave global al proceso, y con una base por
     * periódico dos inquilinos que compartieran clave compartirían permisos — que es privilegio
     * cruzado, no una molestia de rendimiento. Por eso la clave lleva el nombre de la base, y
     * por eso se comprueba: es lo único que separa «el panel va más rápido» de «el redactor de
     * un periódico manda en otro».
     */
    public function test_each_database_has_its_own_permission_cache_key(): void
    {
        $north = CorePackageSelectorSupport::permissionCacheKey('diario_norte_kilkana');
        $south = CorePackageSelectorSupport::permissionCacheKey('diario_sur_kilkana');

        $this->assertNotSame($north, $south);
        $this->assertStringContainsString('diario_norte_kilkana', $north);
        $this->assertStringContainsString('diario_sur_kilkana', $south);
    }

    /** Un nombre de base que no valida no puede fabricar la clave de nadie. */
    public function test_a_database_with_an_invalid_name_produces_no_key(): void
    {
        $this->expectException(\RuntimeException::class);
        CorePackageSelectorSupport::permissionCacheKey('otra`; DROP');
    }
}
