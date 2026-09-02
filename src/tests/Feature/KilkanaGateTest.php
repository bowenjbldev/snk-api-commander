<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaGateController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * El gate lo corre la salida pública; aquí se guarda el veredicto. Lo que se fija: una pasada
 * sustituye la anterior entera, una ruta sin construir no falla, el raíl recibe la cifra sola,
 * bloquear deja rastro en el registro, y correrlo es de quien publica.
 */
class KilkanaGateTest extends TestCase
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

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_una_pasada_sustituye_la_anterior_y_una_ruta_sin_construir_no_falla(): void
    {
        $this->actingAs($this->cuenta('marta@eldiario.example', 'director'), 'sanctum');
        $nueve = array_fill(0, 9, true);
        $conFallo = $nueve;
        $conFallo[6] = false;

        $primera = $this->gate()->store($this->pasada([
            ['url' => '/', 'kind' => 'front page', 'passing' => $nueve],
            ['url' => '/2026/07/01/plan', 'kind' => 'post', 'passing' => $conFallo],
            ['url' => '/rss.xml', 'kind' => 'xml', 'passing' => null],
        ]))->getData(true);

        $this->assertSame(1, $primera['failing']);
        $this->assertCount(3, $primera['data']);
        $this->assertNull($primera['data'][2]['passing']);
        $this->assertNull($primera['data'][2]['checked_at']);
        $this->assertNotNull($primera['last_run_at']);
        $this->assertSame('blocked the deploy:', SnkKilkanaActivityModel::query()->where('kind', 'gate')->value('verb'));

        $segunda = $this->gate()->store($this->pasada([
            ['url' => '/', 'kind' => 'front page', 'passing' => $nueve],
        ]))->getData(true);

        $this->assertCount(1, $segunda['data'], 'La pasada nueva sustituye la anterior entera.');
        $this->assertSame(0, $segunda['failing']);
        $this->assertSame(['failing' => 0], $this->gate(['counts' => '1'])->index()->getData(true));
    }

    public function test_correrlo_es_de_quien_publica_y_leerlo_de_quien_abre_el_panel(): void
    {
        $this->actingAs($this->cuenta('ana@eldiario.example', 'redactor'), 'sanctum');
        $this->assertSame(200, $this->gate()->index()->getStatusCode());
        $this->assertSame(403, $this->gate()->store($this->pasada([['url' => '/', 'kind' => 'front page', 'passing' => null]]))->getStatusCode());
        $this->assertSame(0, SnkKilkanaGateResultModel::query()->count());
    }

    private function pasada(array $results): Request
    {
        return Request::create('/api/v1/kilkana/gate', 'PUT', ['results' => $results]);
    }

    private function gate(array $query = []): SnkKilkanaGateController
    {
        request()->query->replace($query);

        return app(SnkKilkanaGateController::class);
    }

    private function cuenta(string $email, string $rol): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => 'Alguien', 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($rol);

        return $user;
    }
}
