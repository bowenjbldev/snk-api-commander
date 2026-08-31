<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Tests\TestCase;

/**
 * Quien elige el periodico que se siembra es la BASE, no quien lanza el comando.
 *
 * El interruptor de entorno dice si esta instalacion admite datos de demo; el perfil dice de que
 * periodico son. Con solo el primero, `core:seed --client=otro` con la variable puesta vuelca el
 * archivo de un periodico dentro de la base de otro — y eso ya paso: 523 piezas de El Diario
 * dentro de dos bases que no eran suyas.
 */
class KilkanaSeedProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        config(['kilkana.kilkana.seed_demo' => true]);
    }

    /** Un periodico declarado sin datos de demo se queda con su cabecera y sin archivo ajeno. */
    public function test_el_perfil_manda_sobre_el_interruptor_de_entorno(): void
    {
        $this->sembrarComo('diario_norte_kilkana');

        $ajustes = SnkKilkanaSettingModel::query()->find(1);

        $this->assertSame('Diario Norte', $ajustes?->tenant_name);
        $this->assertSame(0, SnkKilkanaPostModel::query()->count(), 'El archivo de otro no entra aqui.');
        // Y las cuentas tampoco: una contrasena conocida dentro de la base de un cliente es un
        // acceso ajeno a su panel, y `seedPermissions` corre ANTES de la puerta del archivo.
        $this->assertSame(0, CoreUserModel::query()->count(), 'Ni una cuenta de demo.');
    }

    /** Un cliente real, sin perfil: ni identidad ni contenido. Se nombra al darse de alta. */
    public function test_una_base_sin_perfil_no_recibe_nada(): void
    {
        $this->sembrarComo('cliente_nuevo_kilkana');

        $this->assertNull(SnkKilkanaSettingModel::query()->find(1)?->tenant_name);
        $this->assertSame(0, SnkKilkanaPostModel::query()->count());
    }

    /**
     * Un nombre de base que Core no valida no tumba la siembra.
     *
     * `seedPermissions` compone con el la clave de la cache de permisos, y `permissionCacheKey`
     * rechaza lo que no sea un identificador de inquilino. Un guion basta: la siembra entera
     * moria con `Unsafe tenant database identifier` antes de escribir una fila.
     */
    public function test_un_nombre_de_base_invalido_no_tumba_la_siembra(): void
    {
        $this->sembrarComo('cliente-con-guion_kilkana');

        $this->assertSame(0, SnkKilkanaPostModel::query()->count());
    }

    /** El catalogo no puede tener dos perfiles peleandose por el mismo cliente. */
    public function test_ningun_cliente_aparece_en_dos_perfiles(): void
    {
        $vistos = [];

        foreach (glob(base_path('packages/Kilkana/database/seeders/data/profiles/*.json')) as $ruta) {
            $perfil = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($perfil['clients'] ?? null, basename($ruta).' declara de quien es.');
            $this->assertIsBool($perfil['demo'] ?? null, basename($ruta).' dice si trae datos de demo.');

            foreach ($perfil['clients'] as $cliente) {
                $this->assertArrayNotHasKey($cliente, $vistos, "{$cliente} lo reclaman dos perfiles.");
                $vistos[$cliente] = basename($ruta);
            }
        }

        $this->assertNotSame([], $vistos, 'El catalogo de perfiles no puede estar vacio.');
    }

    /**
     * Siembra como si la conexion apuntase a esa base.
     *
     * El nombre se cambia SIN `DB::purge`: la conexion sigue siendo la sqlite en memoria que
     * monto `setUp`, y lo unico que cambia es de que cliente se cree el seeder que esta
     * sembrando. Purgarla aqui por simetria haria que la prueba intentase abrir un fichero
     * sqlite llamado `diario_norte_kilkana`.
     */
    private function sembrarComo(string $base): void
    {
        config(['database.connections.tenant.database' => $base]);

        (new SnkKilkanaDatabaseSeeder)->run();
    }
}
