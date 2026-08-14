<?php

namespace Packages\Commander\Services;

use Illuminate\Support\Str;
use Packages\Commander\Metrics\CommanderBuiltInMetricMeasurer;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderApplicationRegistryService
{
    private const METRICS = [
        'requests_day' => ['name' => 'Requests per day', 'unit' => 'count', 'kind' => 'counter', 'window' => 'day'],
        'disk_db_mb' => ['name' => 'Database disk', 'unit' => 'mb', 'kind' => 'gauge', 'window' => 'current'],
        'disk_storage_mb' => ['name' => 'Storage disk', 'unit' => 'mb', 'kind' => 'gauge', 'window' => 'current'],
        'disk_total_mb' => ['name' => 'Total disk', 'unit' => 'mb', 'kind' => 'gauge', 'window' => 'current'],
        'users_total' => ['name' => 'Users', 'unit' => 'count', 'kind' => 'gauge', 'window' => 'current'],
    ];

    /** @return string[] */
    public function availableOnDisk(): array
    {
        return $this->discovery()->availableBusinessPackages();
    }

    public function hasImplementation(string $client, string $package): bool
    {
        return $this->discovery()->hasImplementation($client, $package);
    }

    /** @return array{applications: int, metrics: int} */
    public function sync(): array
    {
        $now = now();
        CommanderApplicationModel::query()->update(['code_status' => 'missing']);
        $applicationCount = 0;
        $metricCount = 0;

        foreach ($this->availableOnDisk() as $package) {
            $application = CommanderApplicationModel::updateOrCreate(
                ['package_name' => $package],
                [
                    'slug' => CorePackageSelectorSupport::packageToSubdomain($package),
                    'name' => $package,
                    'code_status' => 'available',
                    'last_synced_at' => $now,
                ],
            );
            $applicationCount++;

            $configured = (array) config(Str::lower($package).'.commander.metrics', []);
            $definitions = array_replace_recursive(self::METRICS, $configured);
            $activeKeys = [];

            foreach ($definitions as $key => $definition) {
                $activeKeys[] = $key;
                CommanderApplicationMetricModel::updateOrCreate(
                    ['application_id' => $application->id, 'key' => $key],
                    [
                        'name' => $definition['name'],
                        'unit' => $definition['unit'],
                        'kind' => $definition['kind'] ?? 'gauge',
                        'window' => $definition['window'] ?? 'current',
                        'measurer' => $definition['measurer'] ?? CommanderBuiltInMetricMeasurer::class,
                        'status' => 'active',
                    ],
                );
                $metricCount++;
            }

            CommanderApplicationMetricModel::query()
                ->where('application_id', $application->id)
                ->whereNotIn('key', $activeKeys)
                ->update(['status' => 'inactive']);
        }

        return ['applications' => $applicationCount, 'metrics' => $metricCount];
    }

    private function discovery(): CorePackageDiscoverySupport
    {
        return CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
    }
}
