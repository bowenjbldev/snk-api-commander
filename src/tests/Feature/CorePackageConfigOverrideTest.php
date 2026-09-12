<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * La sobrescritura de la config global por paquete vive en el RESOLUTOR (cliente primero): el
 * subdominio es el cliente y el slug del paquete va en la ruta. Fusiona {slug} y {cliente}.{slug}.
 */
class CorePackageConfigOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function readyInstance(string $clientSlug, string $slug): void
    {
        $account = CommanderAccountModel::create([
            'name' => strtoupper($clientSlug), 'email' => "ops@{$clientSlug}.test",
        ]);
        $client = CommanderClientModel::create([
            'account_id' => $account->id, 'slug' => $clientSlug,
            'name' => strtoupper($clientSlug), 'email' => "ops@{$clientSlug}.test", 'status' => 'active',
        ]);
        $application = CommanderApplicationModel::create([
            'package_name' => Str::studly($slug), 'name' => Str::headline($slug), 'slug' => $slug,
            'status' => 'active', 'code_status' => 'available',
        ]);

        CommanderInstanceModel::create([
            'database' => CorePackageSelectorSupport::databaseName($clientSlug, $slug),
            'database_username' => 'tenant_1', 'database_password' => 'runtime-secret',
            'client_id' => $client->id, 'application_id' => $application->id,
            'service_status' => 'active', 'provisioning_status' => 'ready', 'provisioned_at' => now(),
        ]);
    }

    /** Corre el resolutor para (host, slug) y captura la config global vista dentro de la petición. */
    private function captureFor(string $host, string $slug): array
    {
        $request = Request::create("https://{$host}/api/v1/{$slug}/x", 'GET');
        $seen = [];

        app(CoreResolveInstanceFromHostMiddleware::class)->handle($request, function () use (&$seen, $slug) {
            $packageConfigKey = strtolower(Str::studly($slug));
            $seen = [
                'app_name' => config('app.name'),
                'session_lifetime' => config('session.lifetime'),
                'package_config' => config("{$packageConfigKey}.{$packageConfigKey}"),
            ];

            return response('ok');
        }, $slug);

        return $seen;
    }

    public function test_package_overrides_apply_in_resolver(): void
    {
        $this->readyInstance('acme', 'shop');
        $originalName = config('app.name');
        $originalLifetime = config('session.lifetime');
        config([
            'shop.app' => ['name' => 'SharedShop'],
            'shop.session' => ['lifetime' => 120],
            'acme.shop.session' => ['lifetime' => 999],
        ]);

        $seen = $this->captureFor('acme.commander.test', 'shop');

        $this->assertSame('SharedShop', $seen['app_name']);
        $this->assertSame(999, $seen['session_lifetime']);
        $this->assertSame($originalName, config('app.name'));
        $this->assertSame($originalLifetime, config('session.lifetime'));
        $this->assertNull(app(CoreActiveInstanceSupport::class)->current());
    }

    public function test_hyphenated_client_overlay_merges_package_config_and_is_restored(): void
    {
        $this->readyInstance('el-mundo', 'news-room');
        $originalName = config('app.name');
        config([
            'newsroom.app' => ['name' => 'Shared newsroom'],
            'elmundo.newsroom.app' => ['name' => 'El Mundo newsroom'],
            'newsroom.newsroom' => [
                'theme' => 'standard',
                'limits' => ['disk' => 100, 'users' => 10],
            ],
            'elmundo.newsroom.newsroom' => [
                'theme' => 'brand',
                'limits' => ['users' => 20],
            ],
        ]);

        $seen = $this->captureFor('el-mundo.commander.test', 'news-room');

        $this->assertSame('El Mundo newsroom', $seen['app_name']);
        $this->assertSame('brand', $seen['package_config']['theme']);
        $this->assertSame(['disk' => 100, 'users' => 20], $seen['package_config']['limits']);
        $this->assertSame('standard', config('newsroom.newsroom.theme'));
        $this->assertSame(10, config('newsroom.newsroom.limits.users'));
        $this->assertSame($originalName, config('app.name'));
    }

    public function test_client_domain_parameter_is_not_forwarded_to_the_controller(): void
    {
        $this->readyInstance('demo', 'kilkana');
        $request = Request::create('https://demo.commander.test/api/v1/kilkana/public/layouts/front', 'GET');
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        $this->assertSame(['client' => 'demo', 'layout' => 'front'], $route->parameters());
        $parameters = null;

        app(CoreResolveInstanceFromHostMiddleware::class)->handle(
            $request,
            function () use ($request, &$parameters) {
                $parameters = $request->route()?->parameters();

                return response('ok');
            },
            'kilkana',
        );

        $this->assertSame(['layout' => 'front'], $parameters);
    }

    public function test_unprovisioned_aborts_404(): void
    {
        config(['ghost.shop.app' => ['name' => 'GhostOverride']]); // declarado pero sin cliente/fila

        $this->expectException(NotFoundHttpException::class);

        $this->captureFor('ghost.commander.test', 'shop');
    }
}
