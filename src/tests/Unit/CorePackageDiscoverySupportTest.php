<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use PHPUnit\Framework\TestCase;

class CorePackageDiscoverySupportTest extends TestCase
{
    private string $packages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packages = sys_get_temp_dir().'/core-packages-'.bin2hex(random_bytes(6));
        foreach (['Kilkana/routes', 'ElMundo/Kilkana/config', 'Acme/Shop/routes', 'Acme/Shop/database/migrations', 'ConfigOnly/config', 'EmptyClient'] as $path) {
            mkdir("{$this->packages}/{$path}", 0777, true);
        }
        foreach (['Kilkana/routes/api.php', 'ElMundo/Kilkana/config/kilkana.php', 'Acme/Shop/routes/api.php', 'Acme/Shop/database/migrations/create_shop.php', 'ConfigOnly/config/app.php'] as $file) {
            file_put_contents("{$this->packages}/{$file}", '<?php');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->packages);

        parent::tearDown();
    }

    public function test_resolves_shared_overlay_custom_only_and_missing_packages(): void
    {
        $discovery = new CorePackageDiscoverySupport($this->packages);

        $this->assertSame(['Kilkana', 'ElMundo\\Kilkana'], $discovery->implementationModules('el-mundo', 'kilkana'));
        $this->assertSame(['Kilkana'], $discovery->implementationModules('elconfidencial', 'kilkana'));
        $this->assertSame(['Acme\\Shop'], $discovery->implementationModules('acme', 'shop'));
        $this->assertSame([], $discovery->implementationModules('acme', 'ghost'));
        $this->assertSame([], $discovery->implementationModules('acme', 'config-only'));
        $this->assertSame(['Kilkana', 'Shop'], $discovery->availableBusinessPackages());
    }

    public function test_client_subdomains_are_dns_safe_and_reserve_platform_hosts(): void
    {
        $this->assertTrue(CorePackageSelectorSupport::isValidClientSubdomain('el-mundo'));
        $this->assertTrue(CorePackageSelectorSupport::isValidClientSubdomain(str_repeat('a', 63)));
        $this->assertFalse(CorePackageSelectorSupport::isValidClientSubdomain(str_repeat('a', 64)));
        $this->assertFalse(CorePackageSelectorSupport::isValidClientSubdomain('bad_slug'));
        $this->assertFalse(CorePackageSelectorSupport::isValidClientSubdomain('commander'));
    }
}
