<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Http\Middleware\CoreResolvePackageFromHostMiddleware;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * El override de config global por package vive en el RESOLUTOR (cliente-primero): el subdominio es
 * el cliente y el slug del package va en el path. Fusiona {slug} y {cliente}.{slug}.
 */
class CorePackageConfigOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function provisionedPackage(string $clientSlug, string $slug): void
    {
        $client = CommanderClientModel::create([
            'slug' => $clientSlug, 'name' => strtoupper($clientSlug), 'email' => "ops@{$clientSlug}.test", 'status' => 'active',
        ]);

        CommanderPackageModel::create([
            'name' => 'Shop', 'slug' => $slug,
            'database' => CorePackageSelectorSupport::databaseName($clientSlug, $slug),
            'database_username' => 'tenant_1', 'database_password' => 'runtime-secret',
            'client_id' => $client->id, 'status' => 'active', 'provisioned_at' => now(),
        ]);
    }

    /** Corre el resolutor para (host, slug) y captura la config global vista dentro del request. */
    private function captureFor(string $host, string $slug): array
    {
        $request = Request::create("https://{$host}/api/v1/{$slug}/x", 'GET');
        $seen = [];

        app(CoreResolvePackageFromHostMiddleware::class)->handle($request, function () use (&$seen, $slug) {
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
        $this->provisionedPackage('acme', 'shop');
        $originalName = config('app.name');
        $originalLifetime = config('session.lifetime');
        config([
            'shop.app' => ['name' => 'SharedShop'],
            'shop.session' => ['lifetime' => 120],
            'acme.shop.session' => ['lifetime' => 999],
        ]);

        $seen = $this->captureFor('acme.snake.test', 'shop');

        $this->assertSame('SharedShop', $seen['app_name']);
        $this->assertSame(999, $seen['session_lifetime']);
        $this->assertSame($originalName, config('app.name'));
        $this->assertSame($originalLifetime, config('session.lifetime'));
        $this->assertNull(app(CoreActivePackageSupport::class)->current());
    }

    public function test_hyphenated_client_overlay_merges_package_config_and_is_restored(): void
    {
        $this->provisionedPackage('el-mundo', 'news-room');
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

        $seen = $this->captureFor('el-mundo.snake.test', 'news-room');

        $this->assertSame('El Mundo newsroom', $seen['app_name']);
        $this->assertSame('brand', $seen['package_config']['theme']);
        $this->assertSame(['disk' => 100, 'users' => 20], $seen['package_config']['limits']);
        $this->assertSame('standard', config('newsroom.newsroom.theme'));
        $this->assertSame(10, config('newsroom.newsroom.limits.users'));
        $this->assertSame($originalName, config('app.name'));
    }

    public function test_unprovisioned_aborts_404(): void
    {
        config(['ghost.shop.app' => ['name' => 'GhostOverride']]); // declarado pero sin cliente/fila

        $this->expectException(NotFoundHttpException::class);

        $this->captureFor('ghost.snake.test', 'shop');
    }
}
