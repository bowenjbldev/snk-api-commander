<?php

namespace Packages\Commander\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Services\CoreInstanceStateService;

class CommanderStatusCommand extends Command
{
    protected $signature = 'commander:status
        {instance? : Numeric instance id}
        {--set= : active|grace|suspended}
        {--reason= : State reason}
        {--message= : Client-facing message}
        {--grace-until= : ISO-8601 or relative date}';

    protected $description = 'Show or change an instance service state';

    public function handle(CoreInstanceStateService $stateService): int
    {
        $id = $this->argument('instance');
        if ($id === null) {
            $this->table(
                ['ID', 'Client', 'Application', 'Provisioning', 'Service'],
                CommanderInstanceModel::query()->with(['client', 'application'])->get()->map(fn ($instance) => [
                    $instance->id,
                    $instance->client->slug,
                    $instance->application->slug,
                    $instance->provisioning_status,
                    $instance->service_status->value,
                ]),
            );

            return self::SUCCESS;
        }

        $instance = CommanderInstanceModel::query()->find($id);
        if ($instance === null) {
            $this->error("Instance [{$id}] not found.");

            return self::FAILURE;
        }

        if (is_string($this->option('set')) && $this->option('set') !== '') {
            $status = CoreServiceStatusEnum::tryFrom($this->option('set'));
            if ($status === null) {
                $this->error('Invalid state: use active|grace|suspended.');

                return self::FAILURE;
            }
            $graceUntil = $status === CoreServiceStatusEnum::Grace
                ? CarbonImmutable::parse($this->option('grace-until') ?: '+72 hours')
                : null;
            $stateService->set($status, $this->option('reason') ?: null, $this->option('message') ?: null, $graceUntil, $instance->id);
        }

        $state = $stateService->current($instance->id);
        $this->table(['Field', 'Value'], [
            ['status', $state->status->value],
            ['effective_status', $state->effectiveStatus()->value],
            ['reason', $state->reason ?? '-'],
            ['message', $state->message ?? '-'],
            ['grace_until', $state->graceUntil?->toIso8601String() ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
