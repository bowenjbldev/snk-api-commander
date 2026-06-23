<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Http\Middleware\CoreResolvePackageFromHostMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * El override de config global por package vive en el RESOLUTOR (cliente-primero): el subdominio es
 * el cliente y el slug del package va en el path. El namespace de config es {cliente}.{slug}
 * (acme.shop), cargado por el discovery desde packages/{Client}/{Package}/config/*.php.
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
            'name' => 'Shop', 'slug' => $slug, 'database' => null, // database null => el resolutor no conmuta BD
            'client_id' => $client->id, 'status' => 'active', 'provisioned_at' => now(),
        ]);
    }

    /** Corre el resolutor para (host, slug) y captura la config global vista dentro del request. */
    private function captureFor(string $host, string $slug): array
    {
        $request = Request::create("https://{$host}/api/v1/{$slug}/x", 'GET');
        $seen = [];

        app(CoreResolvePackageFromHostMiddleware::class)->handle($request, function () use (&$seen) {
            $seen = [
                'app_name' => config('app.name'),
                'session_lifetime' => config('session.lifetime'),
            ];

            return response('ok');
        }, $slug);

        return $seen;
    }

    public function test_package_overrides_apply_in_resolver(): void
    {
        $this->provisionedPackage('acme', 'shop');
        // Namespace {cliente}.{slug}: lo carga el discovery; aquí lo declaramos a mano.
        config(['acme.shop.app' => ['name' => 'ShopOverride'], 'acme.shop.session' => ['lifetime' => 999]]);

        $seen = $this->captureFor('acme.snake.test', 'shop');

        $this->assertSame('ShopOverride', $seen['app_name']);
        $this->assertSame(999, $seen['session_lifetime']);
    }

    public function test_unprovisioned_aborts_404(): void
    {
        config(['ghost.shop.app' => ['name' => 'GhostOverride']]); // declarado pero sin cliente/fila

        $this->expectException(NotFoundHttpException::class);

        $this->captureFor('ghost.snake.test', 'shop');
    }
}
