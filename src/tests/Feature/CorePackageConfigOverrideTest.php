<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Http\Middleware\CoreApplyPackageConfigMiddleware;
use Tests\TestCase;

class CorePackageConfigOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** Corre el middleware global para un host y captura la config vista dentro del request. */
    private function captureFor(string $host): array
    {
        $request = Request::create("https://{$host}/api/v1/x", 'GET');
        $seen = [];

        (new CoreApplyPackageConfigMiddleware())->handle($request, function (Request $req) use (&$seen) {
            $seen = [
                'app_name' => config('app.name'),
                'session_lifetime' => config('session.lifetime'),
                'stashed' => $req->attributes->get('core_active_package'),
            ];

            return response('ok');
        });

        return $seen;
    }

    public function test_package_overrides_apply_on_its_subdomain(): void
    {
        CommanderPackageModel::create(['name' => 'Demo', 'subdomain' => 'demo', 'database' => 'demo', 'status' => 'active']);
        config(['demo.app' => ['name' => 'DemoOverride'], 'demo.session' => ['lifetime' => 999]]);

        $seen = $this->captureFor('demo.snake.test');

        $this->assertSame('DemoOverride', $seen['app_name']);
        $this->assertSame(999, $seen['session_lifetime']);
        // Stashea el package para que CoreResolvePackageFromHostMiddleware lo reuse sin re-query.
        $this->assertInstanceOf(CommanderPackageModel::class, $seen['stashed']);
    }

    public function test_no_override_on_operator_host(): void
    {
        $original = config('app.name');
        CommanderPackageModel::create(['name' => 'Demo', 'subdomain' => 'demo', 'status' => 'active']);
        config(['demo.app' => ['name' => 'DemoOverride']]);

        $seen = $this->captureFor('commander.snake.test');

        $this->assertSame($original, $seen['app_name']);
        $this->assertNull($seen['stashed']);
    }

    public function test_no_override_for_unprovisioned_subdomain(): void
    {
        $original = config('app.name');
        config(['ghost.app' => ['name' => 'GhostOverride']]); // declarado pero sin fila 'ghost'

        $seen = $this->captureFor('ghost.snake.test');

        $this->assertSame($original, $seen['app_name']);
        $this->assertNull($seen['stashed']);
    }
}
