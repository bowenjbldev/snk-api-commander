<?php

namespace Packages\Commander\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderDeprovisionInstanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $instanceId)
    {
        $this->onQueue('provisioning');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("tenant-instance:{$this->instanceId}"))->shared()->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $instance = CommanderInstanceModel::query()->with('subscriptionItem.subscription')->findOrFail($this->instanceId);
        if (! in_array($instance->provisioning_status, ['deprovisioning', 'failed'], true)) {
            return;
        }

        try {
            $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                $username = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);
                DB::connection('provisioner')->statement("DROP DATABASE IF EXISTS `{$database}`");
                DB::connection('provisioner')->statement("DROP USER IF EXISTS '{$username}'@'%'");
            }
            Storage::disk('public')->deleteDirectory("media/{$database}");

            DB::connection('commander')->transaction(function () use ($instance): void {
                CommanderRequestLogModel::query()->where('instance_id', $instance->id)->delete();
                CommanderQuotaModel::query()->where('instance_id', $instance->id)->update([
                    'current_value' => 0,
                    'threshold_reached_at' => null,
                    'exceeded_at' => null,
                    'upgrade_recommended_at' => null,
                    'consecutive_exceeded' => 0,
                    'measured_at' => null,
                ]);
                $item = $instance->subscriptionItem;
                if ($item !== null) {
                    $item->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
                }
                if ($item?->subscription?->pricing_mode === 'catalog') {
                    $item->subscription->forceFill(['status' => 'canceled', 'ended_at' => now()])->save();
                }

                $instance->forceFill([
                    'database_username' => null,
                    'database_password' => null,
                    'provisioning_status' => 'deprovisioned',
                    'service_status' => 'active',
                    'reason' => null,
                    'message' => null,
                    'last_error' => null,
                    'grace_until' => null,
                    'changed_at' => now(),
                    'requests_24h' => 0,
                    'quota_health' => 0,
                    'last_seen' => null,
                    'provisioned_at' => null,
                    'deprovisioned_at' => now(),
                ])->save();
            });
        } catch (\Throwable $error) {
            $instance->forceFill([
                'provisioning_status' => 'failed',
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
            ])->save();

            throw $error;
        }
    }
}
