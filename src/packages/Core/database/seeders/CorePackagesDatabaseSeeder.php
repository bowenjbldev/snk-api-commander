<?php

namespace Packages\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Packages\Core\Support\CorePackageDiscoverySupport;

class CorePackagesDatabaseSeeder extends Seeder
{
    /** @var string|string[]|null */
    public static string|array|null $targetPackage = null;

    private ?CorePackageDiscoverySupport $packageDiscovery = null;

    public function run(): void
    {
        foreach ($this->discoverPackageSeeders() as $seeder) {
            $this->call($seeder);
        }
    }

    /**
     * @return array<class-string<Seeder>>
     */
    private function discoverPackageSeeders(): array
    {
        $seeders = $this->packageDiscovery()->discoverSeederClasses(static::class);

        if (self::$targetPackage !== null) {
            $targets = array_map(
                fn (string $target): string => "Packages\\{$target}\\",
                (array) self::$targetPackage,
            );
            $coreNamespace = 'Packages\\Core\\';

            $seeders = array_values(array_filter($seeders, function (string $seeder) use ($targets, $coreNamespace): bool {
                return str_starts_with($seeder, $coreNamespace)
                    || collect($targets)->contains(fn (string $target): bool => str_starts_with($seeder, $target));
            }));
        }

        usort($seeders, function (string $a, string $b): int {
            $aIsCore = str_starts_with($a, 'Packages\\Core\\');
            $bIsCore = str_starts_with($b, 'Packages\\Core\\');

            if ($aIsCore === $bIsCore) {
                return strcmp($a, $b);
            }

            return $aIsCore ? -1 : 1;
        });

        return $seeders;
    }

    private function packageDiscovery(): CorePackageDiscoverySupport
    {
        if ($this->packageDiscovery === null) {
            $this->packageDiscovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
        }

        return $this->packageDiscovery;
    }
}
