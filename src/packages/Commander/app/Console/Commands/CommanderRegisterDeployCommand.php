<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;

/**
 * Registers a live deploy (provisioned by scripts/subscription.sh) in the Commander platform registry
 * so the SPA can control it: stores its base_url + control_secret (encrypted) + a client row.
 * Idempotent — re-running updates the row. Called by subscription.sh after the deploy is up.
 */
class CommanderRegisterDeployCommand extends Command
{
    protected $signature = 'commander:register-deploy
        {deployment : Deployment id/slug (e.g. demoa)}
        {--package=Commander : API_PACKAGE the deploy runs}
        {--base-url= : Control-plane base, e.g. https://demoa.snake.test/api/v1}
        {--secret= : The deploy CORE_CONTROL_SECRET (stored encrypted)}
        {--client= : Client id/slug (defaults to the deployment id)}
        {--client-name= : Human client name (defaults from the client slug)}';

    protected $description = 'Register a live deploy in the Commander registry (base_url + control secret).';

    public function handle(): int
    {
        $deployment = (string) $this->argument('deployment');
        $secret = (string) $this->option('secret');

        if ($secret === '') {
            $this->error('--secret is required (the deploy CORE_CONTROL_SECRET).');

            return self::FAILURE;
        }

        $package = (string) ($this->option('package') ?: 'Commander');
        $baseUrl = (string) ($this->option('base-url') ?: "https://{$deployment}.snake.test/api/v1");
        $clientId = (string) ($this->option('client') ?: $deployment);
        $clientName = (string) ($this->option('client-name') ?: Str::headline($clientId));
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: "{$deployment}.snake.test";

        // Activation path: a 'provisioning' (or already-registered) row exists — created either by the
        // Commander's client-first flow or a previous run. Fill the real base_url/secret and flip to
        // active WITHOUT clobbering the planned client/subscription/package/subdomain.
        if ($existing = DeploymentModel::find($deployment)) {
            $existing->fill([
                'base_url' => $baseUrl,
                'control_secret' => $secret,
                'control_secret_rotated_at' => now(),
                'version' => $existing->version === 'pending' ? 'v1.0.0' : $existing->version,
                'last_seen' => now(),
                'status' => 'active',
                'reason' => null,
                'message' => null,
                'grace_until' => null,
                'changed_by' => 'cms',
                'changed_at' => now(),
            ])->save();

            $this->info("Activated deployment [{$deployment}] → {$baseUrl} (client {$existing->client_id}).");

            return self::SUCCESS;
        }

        // Direct CLI flow (no pre-created client): ensure a placeholder client exists, but never
        // overwrite an existing client's billing — firstOrCreate only fills on create.
        ClientModel::firstOrCreate(['id' => $clientId], [
            'name' => $clientName,
            'company' => $clientName,
            'email' => "ops@{$clientId}.local",
            'status' => 'active',
        ]);

        DeploymentModel::create([
            'id' => $deployment,
            'name' => $deployment,
            'subdomain' => $host,
            'client_id' => $clientId,
            'package' => $package,
            'version' => 'v1.0.0',
            'base_url' => $baseUrl,
            'control_secret' => $secret,
            'control_secret_rotated_at' => now(),
            'subscription_id' => null,
            'requests_24h' => 0,
            'quota_health' => 0,
            'last_seen' => now(),
            'status' => 'active',
            'reason' => null,
            'message' => null,
            'grace_until' => null,
            'changed_by' => 'cms',
            'changed_at' => now(),
        ]);

        $this->info("Registered deployment [{$deployment}] → {$baseUrl} (client {$clientId}).");

        return self::SUCCESS;
    }
}
