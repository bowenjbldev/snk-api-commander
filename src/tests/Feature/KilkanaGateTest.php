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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_a_new_run_replaces_the_previous_one_and_an_unbuilt_route_does_not_fail(): void
    {
        $this->actingAs($this->account('marta@eldiario.example', 'editor_in_chief'), 'sanctum');
        $allPassing = array_fill(0, 9, true);
        $withFailure = $allPassing;
        $withFailure[6] = false;

        $first = $this->gate()->store($this->gateRun([
            ['url' => '/', 'kind' => 'front page', 'passing' => $allPassing],
            ['url' => '/2026/07/01/plan', 'kind' => 'post', 'passing' => $withFailure],
            ['url' => '/rss.xml', 'kind' => 'xml', 'passing' => null],
        ]))->getData(true);

        $this->assertSame(1, $first['failing']);
        $this->assertCount(3, $first['data']);
        $this->assertNull($first['data'][2]['passing']);
        $this->assertNull($first['data'][2]['checked_at']);
        $this->assertNotNull($first['last_run_at']);
        $this->assertSame('deploy_blocked', SnkKilkanaActivityModel::query()->where('kind', 'gate')->value('verb'));

        $second = $this->gate()->store($this->gateRun([
            ['url' => '/', 'kind' => 'front page', 'passing' => $allPassing],
        ]))->getData(true);

        $this->assertCount(1, $second['data'], 'La pasada nueva sustituye la anterior entera.');
        $this->assertSame(0, $second['failing']);
        $this->assertSame(['failing' => 0], $this->gate(['counts' => '1'])->index()->getData(true));
    }

    public function test_running_it_needs_publish_rights_and_reading_it_needs_panel_access(): void
    {
        $this->actingAs($this->account('ana@eldiario.example', 'reporter'), 'sanctum');
        $this->assertSame(200, $this->gate()->index()->getStatusCode());
        $this->assertSame(403, $this->gate()->store($this->gateRun([['url' => '/', 'kind' => 'front page', 'passing' => null]]))->getStatusCode());
        $this->assertSame(0, SnkKilkanaGateResultModel::query()->count());
    }

    private function gateRun(array $results): Request
    {
        return Request::create('/api/v1/kilkana/gate', 'PUT', ['results' => $results]);
    }

    private function gate(array $query = []): SnkKilkanaGateController
    {
        request()->query->replace($query);

        return app(SnkKilkanaGateController::class);
    }

    private function account(string $email, string $role): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => 'Alguien', 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($role);

        return $user;
    }
}
