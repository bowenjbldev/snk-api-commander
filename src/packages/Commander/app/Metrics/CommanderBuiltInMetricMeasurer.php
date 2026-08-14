<?php

namespace Packages\Commander\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Contracts\CommanderMetricMeasurer;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderBuiltInMetricMeasurer implements CommanderMetricMeasurer
{
    public function measure(
        CommanderInstanceModel $instance,
        CommanderApplicationMetricModel $metric,
        bool $tenantConnected,
    ): ?int {
        return match ($metric->key) {
            'requests_day' => (int) CommanderRequestLogModel::query()
                ->where('instance_id', $instance->id)
                ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
                ->count(),
            'disk_db_mb' => $this->databaseSize($tenantConnected),
            'disk_storage_mb' => $this->storageSize($instance),
            'disk_total_mb' => $this->totalDisk($instance, $tenantConnected),
            'users_total' => $tenantConnected
                ? (int) DB::connection('tenant')->table('users')->count()
                : null,
            default => null,
        };
    }

    private function databaseSize(bool $tenantConnected): ?int
    {
        if (! $tenantConnected || DB::connection('tenant')->getDriverName() === 'sqlite') {
            return null;
        }

        $row = DB::connection('tenant')->selectOne(
            'SELECT ROUND(SUM(data_length + index_length) / 1048576) AS mb
             FROM information_schema.tables WHERE table_schema = DATABASE()'
        );

        return (int) ($row->mb ?? 0);
    }

    private function storageSize(CommanderInstanceModel $instance): ?int
    {
        if (empty($instance->database)) {
            return null;
        }

        $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
        $base = storage_path("app/public/media/{$database}");
        if (! is_dir($base)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return (int) round($bytes / 1048576);
    }

    private function totalDisk(CommanderInstanceModel $instance, bool $tenantConnected): ?int
    {
        $database = $this->databaseSize($tenantConnected);
        $storage = $this->storageSize($instance);

        return $database === null || $storage === null ? null : $database + $storage;
    }
}
