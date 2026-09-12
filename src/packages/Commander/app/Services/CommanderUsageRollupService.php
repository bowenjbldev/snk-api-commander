<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderUsageRollupModel;

/**
 * Agrega commander_request_logs en buckets cerrados (UTC) de hora o dia.
 * Idempotente: unique key (period, period_start, route, method, status_class)
 * + upsert; el ultimo bucket procesado se recalcula en cada pasada para
 * absorber escrituras tardias del terminate().
 *
 * El dia se calcula SIEMPRE desde la tabla cruda (los p95 no componen desde
 * los buckets de hora), por eso la retencion debe ser >= 2 dias.
 */
class CommanderUsageRollupService
{
    private const CHUNK = 2000;

    private const PRUNE_BATCH = 5000;

    /**
     * @return int filas upserted
     */
    public function rollup(string $period): int
    {
        if (! in_array($period, ['hour', 'day'], true)) {
            throw new \InvalidArgumentException("Invalid period [$period]: use hour|day.");
        }

        $start = $this->windowStart($period);

        if ($start === null) {
            return 0; // sin datos crudos
        }

        // Solo buckets CERRADOS: el bucket actual queda fuera.
        $endBoundary = $this->truncate(CarbonImmutable::now('UTC'), $period);
        $upserted = 0;

        for ($bucket = $start; $bucket->lessThan($endBoundary); $bucket = $this->advance($bucket, $period)) {
            $upserted += $this->rollupBucket($period, $bucket);
        }

        return $upserted;
    }

    /**
     * @return int filas crudas eliminadas
     */
    public function prune(): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays((int) config('core.metering.retention_days', 30));
        $deleted = 0;

        do {
            $affected = CommanderRequestLogModel::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::PRUNE_BATCH)
                ->delete();
            $deleted += $affected;
        } while ($affected === self::PRUNE_BATCH);

        return $deleted;
    }

    private function windowStart(string $period): ?CarbonImmutable
    {
        // Reprocesa desde el ultimo bucket agregado (upsert lo hace seguro)
        $last = CommanderUsageRollupModel::query()->where('period', $period)->max('period_start');

        if ($last !== null) {
            return $this->truncate(CarbonImmutable::parse($last, 'UTC'), $period);
        }

        $first = CommanderRequestLogModel::query()->min('created_at');

        return $first !== null
            ? $this->truncate(CarbonImmutable::parse($first, 'UTC'), $period)
            : null;
    }

    private function rollupBucket(string $period, CarbonImmutable $bucket): int
    {
        $bucketEnd = $this->advance($bucket, $period);

        /** @var array<string, array{instance_id: int, route: string, method: string, status_class: string, count: int, errors: int, dur_sum: int, durations: int[], req_bytes: int, resp_bytes: int, users: array<int|string, true>}> $groups */
        $groups = [];

        CommanderRequestLogModel::query()
            ->where('created_at', '>=', $bucket)
            ->where('created_at', '<', $bucketEnd)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $route = $row->route ?? '';
                    $class = intdiv((int) $row->status, 100).'xx';
                    $instanceId = $row->instance_id;
                    $key = $instanceId.'|'.$route.'|'.$row->method.'|'.$class;

                    $groups[$key] ??= [
                        'instance_id' => $instanceId,
                        'route' => $route,
                        'method' => $row->method,
                        'status_class' => $class,
                        'count' => 0,
                        'errors' => 0,
                        'dur_sum' => 0,
                        'durations' => [],
                        'req_bytes' => 0,
                        'resp_bytes' => 0,
                        'users' => [],
                    ];

                    $g = &$groups[$key];
                    $g['count']++;
                    $g['errors'] += $row->status >= 400 ? 1 : 0;
                    $g['dur_sum'] += (int) $row->duration_ms;
                    $g['durations'][] = (int) $row->duration_ms;
                    $g['req_bytes'] += (int) ($row->request_bytes ?? 0);
                    $g['resp_bytes'] += (int) ($row->response_bytes ?? 0);
                    if ($row->user_id !== null) {
                        $g['users'][$row->user_id] = true;
                    }
                    unset($g);
                }
            });

        if ($groups === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($groups as $g) {
            sort($g['durations']);
            $p95 = $g['durations'][(int) floor(0.95 * (count($g['durations']) - 1))];

            $rows[] = [
                'instance_id' => $g['instance_id'],
                'period' => $period,
                'period_start' => $bucket,
                'route' => $g['route'],
                'method' => $g['method'],
                'status_class' => $g['status_class'],
                'request_count' => $g['count'],
                'error_count' => $g['errors'],
                'avg_duration_ms' => round($g['dur_sum'] / $g['count'], 2),
                'p95_duration_ms' => $p95,
                'total_request_bytes' => $g['req_bytes'],
                'total_response_bytes' => $g['resp_bytes'],
                'unique_users' => count($g['users']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CommanderUsageRollupModel::query()->upsert(
            $rows,
            ['instance_id', 'period', 'period_start', 'route', 'method', 'status_class'],
            [
                'request_count', 'error_count', 'avg_duration_ms', 'p95_duration_ms',
                'total_request_bytes', 'total_response_bytes', 'unique_users', 'updated_at',
            ]
        );

        return count($rows);
    }

    private function truncate(CarbonImmutable $moment, string $period): CarbonImmutable
    {
        return $period === 'hour' ? $moment->startOfHour() : $moment->startOfDay();
    }

    private function advance(CarbonImmutable $bucket, string $period): CarbonImmutable
    {
        return $period === 'hour' ? $bucket->addHour() : $bucket->addDay();
    }
}
