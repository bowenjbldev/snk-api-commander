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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        config(['kilkana.kilkana.seed_demo' => true]);
    }

    /**
     * Un periodico declarado sin datos de demo se queda con su cabecera y sin archivo ajeno. Si su
     * perfil pide `accounts`, trae las dos cuentas conocidas para poder entrar, y ninguna mas.
     */
    public function test_the_profile_overrides_the_environment_switch(): void
    {
        $this->seedAs('diario_norte_kilkana');

        $settings = SnkKilkanaSettingModel::query()->find(1);

        $this->assertSame('Diario Norte', $settings?->tenant_name);
        $this->assertSame(0, SnkKilkanaPostModel::query()->count(), 'El archivo de otro no entra aqui.');
        // Las cuentas las abre `accounts` y no el archivo: el administrador local y el lector de
        // demo, sin la redaccion de otro periodico.
        $this->assertEqualsCanonicalizing(
            ['admin@snake.com', 'reader@snk.test'],
            CoreUserModel::query()->pluck('email')->all(),
            'Solo las cuentas para entrar.'
        );
    }

    /** Un cliente real, sin perfil: ni identidad ni contenido. Se nombra al darse de alta. */
    public function test_a_database_without_a_profile_receives_nothing(): void
    {
        $this->seedAs('cliente_nuevo_kilkana');

        $this->assertNull(SnkKilkanaSettingModel::query()->find(1)?->tenant_name);
        $this->assertSame(0, SnkKilkanaPostModel::query()->count());
        $this->assertSame(0, CoreUserModel::query()->count(), 'Sin perfil que las pida, ni una cuenta conocida.');
    }

    /**
     * Un nombre de base que Core no valida no tumba la siembra.
     *
     * `seedPermissions` compone con el la clave de la cache de permisos, y `permissionCacheKey`
     * rechaza lo que no sea un identificador de inquilino. Un guion basta: la siembra entera
     * moria con `Unsafe tenant database identifier` antes de escribir una fila.
     */
    public function test_an_invalid_database_name_does_not_break_seeding(): void
    {
        $this->seedAs('cliente-con-guion_kilkana');

        $this->assertSame(0, SnkKilkanaPostModel::query()->count());
    }

    /** El catalogo no puede tener dos perfiles peleandose por el mismo cliente. */
    public function test_no_client_appears_in_two_profiles(): void
    {
        $seen = [];

        foreach (glob(base_path('packages/Kilkana/database/seeders/data/profiles/*.json')) as $path) {
            $profile = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($profile['clients'] ?? null, basename($path).' declara de quien es.');
            $this->assertIsBool($profile['demo'] ?? null, basename($path).' dice si trae datos de demo.');
            if (array_key_exists('accounts', $profile)) {
                $this->assertIsBool($profile['accounts'], basename($path).' dice si trae cuentas.');
            }

            foreach ($profile['clients'] as $client) {
                $this->assertArrayNotHasKey($client, $seen, "{$client} lo reclaman dos perfiles.");
                $seen[$client] = basename($path);
            }
        }

        $this->assertNotSame([], $seen, 'El catalogo de perfiles no puede estar vacio.');
    }

    /**
     * Siembra como si la conexion apuntase a esa base.
     *
     * El nombre se cambia SIN `DB::purge`: la conexion sigue siendo la sqlite en memoria que
     * monto `setUp`, y lo unico que cambia es de que cliente se cree el seeder que esta
     * sembrando. Purgarla aqui por simetria haria que la prueba intentase abrir un fichero
     * sqlite llamado `diario_norte_kilkana`.
     */
    private function seedAs(string $database): void
    {
        config(['database.connections.tenant.database' => $database]);

        (new SnkKilkanaDatabaseSeeder)->run();
    }
}
