<?php

namespace Packages\Commander\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;

/**
 * Refresca los campos denormalizados que el dashboard/lista del Commander leen
 * (requests_24h, quota_health, last_seen), recalculandolos IN-PROCESS desde las tablas
 * commander_* (misma app/BD). El chart de plataforma se sirve agregando rollups en vivo
 * (GET /usage/platform), sin tabla de snapshots que refrescar.
 */
class CommanderRefreshCommand extends Command
{
    protected $signature = 'commander:refresh';

    protected $description = 'Refresh package KPIs (requests_24h, quota_health, last_seen) from the commander_* tables (in-process)';

    public function handle(): int
    {
        $packages = CommanderPackageModel::query()->whereNotNull('client_id')->get();
        $since24 = CarbonImmutable::now()->subDay();

        foreach ($packages as $package) {
            $requests24h = (int) CommanderRequestLogModel::query()
                ->where('package_id', $package->id)
                ->where('occurred_at', '>=', $since24)
                ->count();

            $lastSeen = CommanderRequestLogModel::query()->where('package_id', $package->id)->max('occurred_at');

            // quota_health = peor ratio current/limit sobre las cuotas del package (tabla unica: el
            // motor ya escribio current_value/exceeded_at aqui mismo, no hay espejo que refrescar).
            $worst = 0.0;
            foreach (CommanderQuotaModel::query()->where('package_id', $package->id)->get() as $m) {
                if ($m->limit_value !== null && $m->limit_value > 0) {
                    $worst = max($worst, $m->current_value / $m->limit_value);
                }
            }

            $package->forceFill([
                'requests_24h' => $requests24h,
                'quota_health' => round(min($worst, 99.9999), 4), // cabe en decimal(6,4)
                'last_seen' => $lastSeen ? CarbonImmutable::parse($lastSeen) : $package->last_seen,
            ])->save();

            $this->line(sprintf('  %-16s %6d req/24h   health %.2f', $package->name, $requests24h, round(min($worst, 99.9999), 4)));
        }

        $this->info('Refreshed ' . $packages->count() . ' package(s).');

        return self::SUCCESS;
    }
}
