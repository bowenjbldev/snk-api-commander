<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Tests\TestCase;

/**
 * Quien elige el periódico que se siembra es la BASE, no quien lanza el comando.
 *
 * El interruptor de entorno dice si esta instalación admite datos de demo; el perfil dice de qué
 * periódico son. Con solo el primero, `core:seed --client=otro` con la variable puesta vuelca el
 * archivo de un periódico dentro de la base de otro: cientos de piezas de El Diario en bases que
 * no son suyas.
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
     * Un periódico declarado sin datos de demo se queda con su cabecera y sin archivo ajeno. Si su
     * perfil pide `accounts`, trae las dos cuentas conocidas para poder entrar, y ninguna más.
     */
    public function test_the_profile_overrides_the_environment_switch(): void
    {
        $this->seedAs('diario_norte_kilkana');

        $settings = SnkKilkanaSettingModel::query()->find(1);

        $this->assertSame('Diario Norte', $settings?->tenant_name);
        $this->assertSame(0, SnkKilkanaPostModel::query()->count(), 'El archivo de otro no entra aquí.');
        // Las cuentas las abre `accounts` y no el archivo: el administrador local y el lector de
        // demo, sin la redacción de otro periódico.
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
     * `seedPermissions` compone con él la clave de la caché de permisos, y `permissionCacheKey`
     * rechaza lo que no sea un identificador de inquilino. Un guion basta: sin esta guarda, la
     * siembra entera muere con `Unsafe tenant database identifier` antes de escribir una fila.
     */
    public function test_an_invalid_database_name_does_not_break_seeding(): void
    {
        $this->seedAs('cliente-con-guion_kilkana');

        $this->assertSame(0, SnkKilkanaPostModel::query()->count());
    }

    /** El catálogo no puede tener dos perfiles peleándose por el mismo cliente. */
    public function test_no_client_appears_in_two_profiles(): void
    {
        $seen = [];

        foreach (glob(base_path('packages/Kilkana/database/seeders/data/profiles/*.json')) as $path) {
            $profile = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($profile['clients'] ?? null, basename($path).' declara de quién es.');
            $this->assertIsBool($profile['demo'] ?? null, basename($path).' dice si trae datos de demo.');
            if (array_key_exists('accounts', $profile)) {
                $this->assertIsBool($profile['accounts'], basename($path).' dice si trae cuentas.');
            }

            foreach ($profile['clients'] as $client) {
                $this->assertArrayNotHasKey($client, $seen, "{$client} lo reclaman dos perfiles.");
                $seen[$client] = basename($path);
            }
        }

        $this->assertNotSame([], $seen, 'El catálogo de perfiles no puede estar vacío.');
    }

    /**
     * Siembra como si la conexión apuntase a esa base.
     *
     * El nombre se cambia SIN `DB::purge`: la conexión sigue siendo la sqlite en memoria que
     * montó `setUp`, y lo único que cambia es de qué cliente se cree el seeder que está
     * sembrando. Purgarla aquí por simetría haría que la prueba intentase abrir un fichero
     * sqlite llamado `diario_norte_kilkana`.
     */
    private function seedAs(string $database): void
    {
        config(['database.connections.tenant.database' => $database]);

        (new SnkKilkanaDatabaseSeeder)->run();
    }
}
