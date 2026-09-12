<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Guard;
use Packages\Core\Models\CoreUserModel;
use Tests\TestCase;

/**
 * `last_used_at` se escribe al minuto: una marca reciente no vuelve a escribirse en cada
 * petición, una vieja o inexistente sí.
 */
class CoreAccessTokenLastUsedTest extends TestCase
{
    private string $plainTextToken;

    private int $tokenId;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas, así que basta migrar encima.
        DB::setDefaultConnection('tenant');
        $this->artisan('migrate', ['--database' => 'tenant', '--path' => 'packages/Core/database/migrations'])->run();

        Carbon::setTestNow('2026-09-06 12:00:00');
        $user = CoreUserModel::create(['name' => 'Someone', 'email' => 'someone@tenant.test', 'password' => 'a-password-of-twelve']);
        $token = $user->createToken('test');
        $this->plainTextToken = $token->plainTextToken;
        $this->tokenId = $token->accessToken->getKey();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_stamp_under_a_minute_old_is_not_rewritten(): void
    {
        $this->markLastUsed('2026-09-06 11:59:30');
        $this->authenticate();

        $this->assertSame('2026-09-06 11:59:30', $this->lastUsed());
    }

    public function test_a_stamp_over_a_minute_old_is_rewritten(): void
    {
        $this->markLastUsed('2026-09-06 11:58:00');
        $this->authenticate();

        $this->assertSame('2026-09-06 12:00:00', $this->lastUsed());
    }

    public function test_the_first_stamp_is_always_written(): void
    {
        $this->authenticate();

        $this->assertSame('2026-09-06 12:00:00', $this->lastUsed());
    }

    private function authenticate(): void
    {
        $request = Request::create('/api/v1/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->plainTextToken]);
        $user = (new Guard(app('auth'), null, 'users'))($request);

        $this->assertInstanceOf(CoreUserModel::class, $user);
    }

    private function markLastUsed(string $at): void
    {
        DB::table('personal_access_tokens')->where('id', $this->tokenId)->update(['last_used_at' => $at]);
    }

    private function lastUsed(): ?string
    {
        return DB::table('personal_access_tokens')->where('id', $this->tokenId)->value('last_used_at');
    }
}
