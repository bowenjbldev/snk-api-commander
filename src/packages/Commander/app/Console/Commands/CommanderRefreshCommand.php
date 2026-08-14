<?php

namespace Packages\Commander\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderRequestLogModel;

class CommanderRefreshCommand extends Command
{
    protected $signature = 'commander:refresh';

    protected $description = 'Refresh instance KPIs from Commander metering data';

    public function handle(): int
    {
        $instances = CommanderInstanceModel::query()->with('quotas')->get();
        $since24 = CarbonImmutable::now()->subDay();

        foreach ($instances as $instance) {
            $logs = CommanderRequestLogModel::query()->where('instance_id', $instance->id);
            $requests24h = (clone $logs)->where('created_at', '>=', $since24)->count();
            $lastSeen = (clone $logs)->max('created_at');
            $worst = $instance->quotas->reduce(function (float $value, $quota): float {
                return $quota->limit_value > 0
                    ? max($value, $quota->current_value / $quota->limit_value)
                    : $value;
            }, 0.0);

            $instance->forceFill([
                'requests_24h' => $requests24h,
                'quota_health' => round(min($worst, 99.9999), 4),
                'last_seen' => $lastSeen ? CarbonImmutable::parse($lastSeen) : $instance->last_seen,
            ])->save();
        }

        $this->info('Refreshed '.$instances->count().' instance(s).');

        return self::SUCCESS;
    }
}
