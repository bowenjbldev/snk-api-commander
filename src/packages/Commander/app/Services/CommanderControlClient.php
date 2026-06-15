<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Packages\Commander\Exceptions\CommanderControlException;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Support\CommanderControlSigner;

/**
 * Commander-side client for a deploy's HMAC-signed /control/* endpoints. Each deploy is reached at
 * its own base_url with its own per-deployment control_secret (encrypted in commander_deployments).
 * base_url already carries the /api/v1 prefix, so endpoints append /control/...; the request
 * is signed over the URL's path component, which therefore matches the receiver's request path.
 */
class CommanderControlClient
{
    /** GET /control/state → { state, package, quotas[] }. */
    public function getState(DeploymentModel $deployment): array
    {
        return $this->send($deployment, 'GET', '/control/state');
    }

    /** POST /control/state {status, reason?, message?, grace_until?} → { state }. */
    public function setState(
        DeploymentModel $deployment,
        string $status,
        ?string $reason = null,
        ?string $message = null,
        ?CarbonImmutable $graceUntil = null,
    ): array {
        return $this->send($deployment, 'POST', '/control/state', [
            'status' => $status,
            'reason' => $reason,
            'message' => $message,
            'grace_until' => $graceUntil?->toIso8601String(),
        ]);
    }

    /** GET /control/usage?period=&from=&to=&per_page= → paginated rollups (query not signed). */
    public function getUsage(DeploymentModel $deployment, array $query = []): array
    {
        return $this->send($deployment, 'GET', '/control/usage', null, $query);
    }

    /**
     * POST /control/rotate-secret — deliver a new secret to the deploy, signed with the row's CURRENT
     * secret (so the deploy verifies it before swapping). Caller adopts $newSecret locally on success.
     */
    public function rotateSecret(DeploymentModel $deployment, string $newSecret): array
    {
        return $this->send($deployment, 'POST', '/control/rotate-secret', ['new_secret' => $newSecret]);
    }

    /**
     * Sign + send. A non-null $body is JSON-serialized once, hashed, and sent as the *same*
     * bytes so the receiver's sha256(body) matches. Query params ride the URL, never signed.
     *
     * @throws CommanderControlException on connection failure or a non-2xx response.
     */
    private function send(DeploymentModel $deployment, string $method, string $endpoint, ?array $body = null, array $query = []): array
    {
        $secret = (string) $deployment->control_secret;

        if ($secret === '') {
            throw new CommanderControlException("Deployment {$deployment->id} has no control secret.");
        }

        $url = rtrim((string) $deployment->base_url, '/') . $endpoint;
        // Sign the path only (no query), matching the receiver's '/'.ltrim($request->path()).
        $path = (string) parse_url($url, PHP_URL_PATH);
        $payloadBody = $body === null ? '' : (string) json_encode($body);
        $timestamp = time();
        $signature = CommanderControlSigner::sign($secret, $timestamp, $method, $path, $payloadBody);

        // Package configs are namespaced <package>.<file>: commander/config/commander.php → config('commander.commander.*').
        $config = (array) config('commander.commander.control', []);

        $request = Http::withHeaders([
            'X-Control-Timestamp' => (string) $timestamp,
            'X-Control-Signature' => $signature,
            'Accept' => 'application/json',
        ])
            ->timeout((int) ($config['timeout'] ?? 5))
            ->connectTimeout((int) ($config['connect_timeout'] ?? 3));

        if (array_key_exists('verify', $config) && $config['verify'] === false) {
            $request = $request->withoutVerifying();
        }

        if ($payloadBody !== '') {
            $request = $request->withBody($payloadBody, 'application/json');
        }

        try {
            $response = $request->send($method, $url, $query === [] ? [] : ['query' => $query]);
        } catch (ConnectionException $e) {
            throw new CommanderControlException("Control call to {$deployment->id} failed: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new CommanderControlException(
                "Control call to {$deployment->id} returned {$response->status()}.",
                $response->status(),
                mb_substr((string) $response->body(), 0, 500),
            );
        }

        return (array) $response->json();
    }
}
