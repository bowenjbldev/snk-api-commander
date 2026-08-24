<?php

namespace Tests\Unit;

use Packages\Core\Support\CorePackageSelectorSupport;
use PHPUnit\Framework\TestCase;

class CorePackageSelectorSupportTest extends TestCase
{
    /**
     * La cache de permisos de un inquilino no puede ser la de otro.
     *
     * Spatie cachea el registro de permisos en una clave global al proceso, y con una base por
     * periodico dos inquilinos que compartieran clave compartirian permisos — que es privilegio
     * cruzado, no una molestia de rendimiento. Por eso la clave lleva el nombre de la base, y
     * por eso se comprueba: es lo unico que separa «el panel va mas rapido» de «el redactor de
     * un periodico manda en otro».
     */
    public function test_cada_base_tiene_su_propia_clave_de_permisos(): void
    {
        $norte = CorePackageSelectorSupport::permissionCacheKey('diario_norte_kilkana');
        $sur = CorePackageSelectorSupport::permissionCacheKey('diario_sur_kilkana');

        $this->assertNotSame($norte, $sur);
        $this->assertStringContainsString('diario_norte_kilkana', $norte);
        $this->assertStringContainsString('diario_sur_kilkana', $sur);
    }

    /** Un nombre de base que no valida no puede fabricar la clave de nadie. */
    public function test_una_base_con_nombre_invalido_no_produce_clave(): void
    {
        $this->expectException(\RuntimeException::class);
        CorePackageSelectorSupport::permissionCacheKey('otra`; DROP');
    }
}
