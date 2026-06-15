<?php

namespace Packages\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Packages\Cms\Models\ClientModel;
use Packages\Cms\Models\DeploymentModel;

/**
 * Registers a live deploy (provisioned by scripts/subscription.sh) in the CMS platform registry
 * so the SPA can control it: stores its base_url + control_secret (encrypted) + a client row.
 * Idempotent — re-running updates the row. Called by subscription.sh after the deploy is up.
 */
class CmsRegisterDeployCommand extends Command
{
    protected $signature = 'cms:register-deploy
        {deployment : Deployment id/slug (e.g. demoa)}
        {--package=Cms : API_PACKAGE the deploy runs}
        {--base-url= : Control-plane base, e.g. https://demoa.snake.test/api/v1}
        {--secret= : The deploy CORE_CONTROL_SECRET (stored encrypted)}
        {--client= : Client id/slug (defaults to the deployment id)}
        {--client-name= : Human client name (defaults from the client slug)}';

    protected $description = 'Register a live deploy in the CMS registry (base_url + control secret).';

    public function handle(): int
    {
        $deployment = (string) $this->argument('deployment');
        $secret = (string) $this->option('secret');

        if ($secret === '') {
            $this->error('--secret is required (the deploy CORE_CONTROL_SECRET).');

            return self::FAILURE;
        }

        $package = (string) ($this->option('package') ?: 'Cms');
        $baseUrl = (string) ($this->option('base-url') ?: "https://{$deployment}.snake.test/api/v1");
        $clientId = (string) ($this->option('client') ?: $deployment);
        $clientName = (string) ($this->option('client-name') ?: Str::headline($clientId));
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: "{$deployment}.snake.test";

        ClientModel::updateOrCreate(['id' => $clientId], [
            'name' => $clientName,
            'company' => $clientName,
            'email' => "ops@{$clientId}.local",
            'status' => 'active',
            'updated_at' => now(),
        ]);

        DeploymentModel::updateOrCreate(['id' => $deployment], [
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
            'updated_at' => now(),
        ]);

        $this->info("Registered deployment [{$deployment}] → {$baseUrl} (client {$clientId}).");

        return self::SUCCESS;
    }
}
