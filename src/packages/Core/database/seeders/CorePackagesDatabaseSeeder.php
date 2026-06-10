<?php

namespace Packages\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Packages\Core\Support\CorePackageDiscoverySupport;

class CorePackagesDatabaseSeeder extends Seeder
{
    public static ?string $targetPackage = null;

    private ?CorePackageDiscoverySupport $packageDiscovery = null;

    public function run(): void
    {
        foreach ($this->discoverPackageSeeders() as $seeder) {
            $this->call($seeder);
        }
    }

    /**
     * @return array<class-string<\Illuminate\Database\Seeder>>
     */
    private function discoverPackageSeeders(): array
    {
        $seeders = $this->packageDiscovery()->discoverSeederClasses(static::class);

        if (self::$targetPackage !== null) {
            $targetNamespace = "Packages\\" . self::$targetPackage . "\\";
            $coreNamespace = "Packages\\Core\\";

            $seeders = array_values(array_filter($seeders, function (string $seeder) use ($targetNamespace, $coreNamespace): bool {
                return str_starts_with($seeder, $coreNamespace) || str_starts_with($seeder, $targetNamespace);
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
