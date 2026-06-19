<?php

namespace Packages\Commander\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Commander\Models\QuotaMetricModel;
use Packages\Core\Services\CorePackageStateService;

class StatusCommand extends Command
{
    protected $signature = 'commander:status
        {--set= : Nuevo estado (active|grace|suspended)}
        {--reason= : Codigo de motivo (ej. manual, payment_overdue)}
        {--message= : Mensaje para el cliente (se devuelve en el 402)}
        {--grace-until= : Limite de gracia (ISO-8601 o relativo, ej. "+72 hours")}';

    protected $description = 'Muestra o cambia el estado del deploy (kill-switch local)';

    public function handle(CorePackageStateService $stateService): int
    {
        $set = $this->option('set');

        if (is_string($set) && $set !== '') {
            $status = CorePackageStatusEnum::tryFrom($set);

            if ($status === null) {
                $this->error("Estado invalido [{$set}]: usa active|grace|suspended.");
                return self::FAILURE;
            }

            $graceUntil = null;

            if ($status === CorePackageStatusEnum::Grace) {
                $raw = $this->option('grace-until');
                $graceUntil = is_string($raw) && $raw !== ''
                    ? CarbonImmutable::parse($raw)
                    : CarbonImmutable::now()->addHours((int) config('core.quotas.grace_hours', 72));
            }

            $stateService->set(
                $status,
                $this->option('reason') ?: ($status === CorePackageStatusEnum::Active ? null : 'manual'),
                $this->option('message') ?: null,
                $graceUntil,
                'cli',
            );

            $this->info("Estado actualizado a [{$status->value}].");
        }

        $state = $stateService->current();

        $this->table(['Campo', 'Valor'], [
            ['status', $state->status->value],
            ['effective_status', $state->effectiveStatus()->value],
            ['reason', $state->reason ?? '-'],
            ['message', $state->message ?? '-'],
            ['grace_until', $state->graceUntil?->toIso8601String() ?? '-'],
            ['changed_by', $state->changedBy ?? '-'],
        ]);

        $quotas = QuotaMetricModel::query()->orderBy('metric_key')->get();

        if ($quotas->isNotEmpty()) {
            $this->table(
                ['Metrica', 'Actual', 'Limite', 'on_exceed', 'Estado'],
                $quotas->map(fn ($m) => [
                    $m->metric_key,
                    $m->current_value,
                    $m->limit_value ?? 'sin limite',
                    $m->on_exceed,
                    $m->isOverLimit() ? 'OVER' : 'OK',
                ])
            );
        }

        return self::SUCCESS;
    }
}
