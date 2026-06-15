<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Support\CommanderControlSigner;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Http\Middleware\CoreControlAuthMiddleware;
use Packages\Core\Models\CorePackageStateModel;
use Packages\Core\Models\CoreUserModel;
use Tests\TestCase;

class CommanderControlTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    /**
     * Gold test: a signature produced by CommanderControlSigner must be accepted by the *real*
     * Core receiver middleware — proves byte-identical signing without a live deploy.
     */
    public function test_signer_is_accepted_by_the_real_core_middleware(): void
    {
        $secret = 'shared-control-secret';
        config(['core.control.secret' => $secret, 'core.control.clock_skew_seconds' => 300]);

        // POST with a JSON body.
        $body = json_encode(['status' => 'suspended', 'reason' => 'payment_overdue']);
        $this->assertMiddlewarePasses($secret, 'POST', 'https://deploy.test/api/v1/control/state', $body);

        // GET with an empty body.
        $this->assertMiddlewarePasses($secret, 'GET', 'https://deploy.test/api/v1/control/state', '');
    }

    public function test_middleware_rejects_tampered_body_and_stale_timestamp(): void
    {
        $secret = 'shared-control-secret';
        config(['core.control.secret' => $secret, 'core.control.clock_skew_seconds' => 300]);

        $ts = time();
        $signedBody = json_encode(['status' => 'suspended']);
        $sig = CommanderControlSigner::sign($secret, $ts, 'POST', '/api/v1/control/state', $signedBody);

        // Body changed after signing → 401.
        $tampered = $this->controlRequest('POST', 'https://deploy.test/api/v1/control/state', json_encode(['status' => 'active']), $ts, $sig);
        $this->assertSame(401, $this->runMiddleware($tampered)->getStatusCode());

        // Timestamp outside the skew window → 401.
        $staleTs = $ts - 1000;
        $staleSig = CommanderControlSigner::sign($secret, $staleTs, 'POST', '/api/v1/control/state', $signedBody);
        $stale = $this->controlRequest('POST', 'https://deploy.test/api/v1/control/state', $signedBody, $staleTs, $staleSig);
        $this->assertSame(401, $this->runMiddleware($stale)->getStatusCode());
    }

    public function test_suspend_pushes_signed_state_and_mirrors_locally(): void
    {
        Http::fake([
            '*/control/state' => Http::response([
                'state' => [
                    'status' => 'suspended',
                    'effective_status' => 'suspended',
                    'reason' => 'payment_overdue',
                    'message' => 'Settle the balance.',
                    'grace_until' => null,
                    'changed_by' => 'cms',
                ],
            ], 200),
        ]);

        $admin = $this->admin();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/deployments/kestrel-track/suspend', [
            'reason' => 'payment_overdue',
            'message' => 'Settle the balance.',
        ]);

        $res->assertOk();
        $res->assertJsonPath('state.status', 'suspended');

        $this->assertDatabaseHas('commander_deployments', [
            'id' => 'kestrel-track',
            'status' => 'suspended',
            'reason' => 'payment_overdue',
            'changed_by' => 'cms',
        ]);
        $this->assertDatabaseHas('commander_activity_events', [
            'deployment_id' => 'kestrel-track',
            'kind' => 'suspend',
        ]);

        // The outgoing request carried correct headers AND a byte-identical signature.
        Http::assertSent(function ($request) {
            $deployment = DeploymentModel::find('kestrel-track');
            $ts = (int) $request->header('X-Control-Timestamp')[0];
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $expected = CommanderControlSigner::sign($deployment->control_secret, $ts, 'POST', $path, $request->body());

            return $request->method() === 'POST'
                && str_ends_with($path, '/control/state')
                && $request->hasHeader('X-Control-Signature')
                && hash_equals($expected, $request->header('X-Control-Signature')[0]);
        });
    }

    public function test_control_actions_require_authentication_and_permission(): void
    {
        // Unauthenticated → 401 (auth:sanctum).
        $this->postJson('/api/v1/deployments/kestrel-track/suspend', ['reason' => 'manual'])->assertStatus(401);

        // Authenticated but lacking the control permission → 403.
        $this->admin(); // seed permissions + registry
        $stranger = CoreUserModel::create([
            'name' => 'No Control',
            'email' => 'nocontrol@snake.com',
            'password' => bcrypt('secret1234'),
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/deployments/kestrel-track/suspend', ['reason' => 'manual'])
            ->assertStatus(403);
    }

    public function test_remote_failure_returns_502_and_does_not_mutate_local_state(): void
    {
        Http::fake(['*/control/state' => Http::response(['error' => 'boom'], 500)]);

        $admin = $this->admin();
        $before = DeploymentModel::find('helios-meter')->status; // active

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/deployments/helios-meter/suspend', [
            'reason' => 'manual',
        ]);

        $res->assertStatus(502);
        $this->assertSame($before, DeploymentModel::find('helios-meter')->status);
        $this->assertDatabaseMissing('commander_activity_events', [
            'deployment_id' => 'helios-meter',
            'kind' => 'suspend',
        ]);
    }

    public function test_sync_pulls_state_quotas_and_usage_into_the_mirror(): void
    {
        Http::fake([
            '*/control/state' => Http::response([
                'state' => [
                    'status' => 'active',
                    'effective_status' => 'active',
                    'reason' => null,
                    'message' => null,
                    'grace_until' => null,
                    'changed_by' => 'cms',
                ],
                'package' => 'tracking-api',
                'quotas' => [
                    ['metric' => 'requests_day', 'limit' => 500000, 'current' => 250000, 'measured_at' => null, 'on_exceed' => 'suspend', 'exceeded_at' => null],
                    ['metric' => 'disk_db_mb', 'limit' => 4096, 'current' => 1024, 'measured_at' => null, 'on_exceed' => 'suspend', 'exceeded_at' => null],
                ],
            ], 200),
            '*/control/usage*' => Http::response([
                'data' => [
                    ['request_count' => 100],
                    ['request_count' => 50],
                ],
            ], 200),
        ]);

        $admin = $this->admin();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/deployments/kestrel-track/sync');
        $res->assertOk();

        $this->assertDatabaseHas('commander_quota_metrics', [
            'id' => 'kestrel-track:requests_day',
            'current_value' => 250000,
            'limit_value' => 500000,
        ]);

        $deployment = DeploymentModel::find('kestrel-track');
        $this->assertSame(150, $deployment->requests_24h);
        $this->assertEqualsWithDelta(0.5, $deployment->quota_health, 0.0001); // 250000/500000 is the worst ratio
    }

    public function test_middleware_prefers_a_rotated_db_secret_over_env(): void
    {
        $env = 'env-control-secret-original';
        $rotated = 'rotated-control-secret-value';
        config(['core.control.secret' => $env, 'core.control.clock_skew_seconds' => 300]);
        CorePackageStateModel::query()->updateOrCreate(['id' => 1], ['control_secret' => $rotated]);

        // The rotated (new) secret is accepted by the real middleware...
        $this->assertMiddlewarePasses($rotated, 'GET', 'https://deploy.test/api/v1/control/state', '');

        // ...and the old env secret is now rejected (rotation invalidated it).
        $ts = time();
        $sig = CommanderControlSigner::sign($env, $ts, 'GET', '/api/v1/control/state', '');
        $stale = $this->controlRequest('GET', 'https://deploy.test/api/v1/control/state', '', $ts, $sig);
        $this->assertSame(401, $this->runMiddleware($stale)->getStatusCode());
    }

    public function test_rotate_secret_delivers_new_secret_signed_with_the_old_and_adopts_it(): void
    {
        Http::fake(['*/control/rotate-secret' => Http::response(['rotated' => true, 'suffix' => 'zzzz'], 200)]);

        $admin = $this->admin();
        $before = DeploymentModel::find('kestrel-track')->control_secret;

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/deployments/kestrel-track/rotate-secret');
        $res->assertOk();

        $after = DeploymentModel::find('kestrel-track')->control_secret;
        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('sk_ctl_live_', $after);

        // The outgoing request was signed with the OLD secret and carried the NEW one in the body.
        Http::assertSent(function ($request) use ($before, $after) {
            $ts = (int) $request->header('X-Control-Timestamp')[0];
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $expected = CommanderControlSigner::sign($before, $ts, 'POST', $path, $request->body());
            $body = json_decode((string) $request->body(), true);

            return str_ends_with($path, '/control/rotate-secret')
                && hash_equals($expected, $request->header('X-Control-Signature')[0])
                && ($body['new_secret'] ?? null) === $after;
        });
    }

    public function test_rotate_secret_failure_leaves_the_secret_unchanged(): void
    {
        Http::fake(['*/control/rotate-secret' => Http::response(['error' => 'boom'], 500)]);

        $admin = $this->admin();
        $before = DeploymentModel::find('helios-meter')->control_secret;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/deployments/helios-meter/rotate-secret')
            ->assertStatus(502);

        $this->assertSame($before, DeploymentModel::find('helios-meter')->control_secret);
    }

    // --- helpers ------------------------------------------------------------

    private function assertMiddlewarePasses(string $secret, string $method, string $url, string $body): void
    {
        $ts = time();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $sig = CommanderControlSigner::sign($secret, $ts, $method, $path, $body);

        $response = $this->runMiddleware($this->controlRequest($method, $url, $body, $ts, $sig));

        $this->assertSame(200, $response->getStatusCode(), 'Core middleware rejected a CommanderControlSigner signature.');
    }

    private function controlRequest(string $method, string $url, string $body, int $ts, string $sig): Request
    {
        return Request::create($url, $method, [], [], [], [
            'HTTP_X_CONTROL_TIMESTAMP' => (string) $ts,
            'HTTP_X_CONTROL_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function runMiddleware(Request $request)
    {
        return (new CoreControlAuthMiddleware())->handle($request, fn () => response()->json(['ok' => true]));
    }
}
