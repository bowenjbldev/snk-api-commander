<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

class CoreRemovePackageCommand extends Command
{
    protected $signature = 'remove:package
        {name : Package name (e.g. Analytics)}
        {--force : Delete package without confirmation}';

    protected $description = 'Remove a package scaffold from packages/ and clean composer autoload entries';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $package = $this->normalizePackageName((string) $this->argument('name'));

        if ($package === null) {
            $this->error('Invalid package name. Use letters and numbers (e.g. Analytics).');
            return self::FAILURE;
        }

        if ($package === 'Core') {
            $this->error('Package name "Core" is protected and cannot be removed.');
            return self::FAILURE;
        }

        $targetPath = base_path("packages/{$package}");

        if (!$this->files->isDirectory($targetPath)) {
            $this->error("Package [{$package}] does not exist at {$targetPath}.");
            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $confirmed = confirm(
                label: "This will permanently delete [packages/{$package}]. Continue?",
                default: false
            );

            if (!$confirmed) {
                $this->warn('Operation cancelled.');
                return self::FAILURE;
            }
        }

        $this->files->deleteDirectory($targetPath);
        $this->syncComposerAutoloadRemoval($package);

        $this->info("Package {$package} removed from {$targetPath}");
        $this->line('Next step: run composer dump-autoload manually.');

        return self::SUCCESS;
    }

    private function normalizePackageName(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9]+/', ' ', $raw) ?? '';
        $studly = Str::studly($sanitized);

        if ($studly === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $studly)) {
            return null;
        }

        return $studly;
    }

    private function syncComposerAutoloadRemoval(string $package): void
    {
        $composerPath = base_path('composer.json');

        if (!$this->files->exists($composerPath)) {
            $this->warn('composer.json not found. Skipping autoload cleanup.');
            return;
        }

        $raw = $this->files->get($composerPath);

        try {
            $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->warn('Invalid composer.json JSON: ' . $exception->getMessage());
            return;
        }

        if (!is_array($composer) || !isset($composer['autoload']['psr-4']) || !is_array($composer['autoload']['psr-4'])) {
            $this->warn('composer autoload psr-4 section missing. Skipping autoload cleanup.');
            return;
        }

        $packageNamespace = "Packages\\{$package}\\";
        $seedersNamespace = "Packages\\{$package}\\Database\\Seeders\\";

        $removed = false;

        if (array_key_exists($packageNamespace, $composer['autoload']['psr-4'])) {
            unset($composer['autoload']['psr-4'][$packageNamespace]);
            $removed = true;
        }

        if (array_key_exists($seedersNamespace, $composer['autoload']['psr-4'])) {
            unset($composer['autoload']['psr-4'][$seedersNamespace]);
            $removed = true;
        }

        if (!$removed) {
            $this->line("No composer autoload entries found for package {$package}.");
            return;
        }

        ksort($composer['autoload']['psr-4'], SORT_STRING);

        $this->files->put(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $this->line("Removed composer autoload entries for package {$package}.");
    }
}
