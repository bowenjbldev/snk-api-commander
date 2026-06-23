<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use Packages\Core\Support\CoreTemplateRendererSupport;

/**
 * Scaffold de un package de negocio per-cliente, ANIDADO: packages/{Client}/{Package} con namespace
 * Packages\{Client}\{Package}\ y clases prefijadas {Client}{Package}* (N2). Nivel 1 = framework
 * (Core/Commander); cada otra carpeta de nivel 1 es un paraguas de cliente con sus packages dentro.
 * La herencia entre packages (un cliente extiende el de otro) la decide el programador a mano.
 */
class CoreMakePackageCommand extends Command
{
    protected $signature = 'make:package
        {client : Cliente / paraguas (e.g. Acme)}
        {package : Package dentro del cliente (e.g. Shop)}
        {--force : Overwrite package if it already exists}
        {--no-dump : Skip the composer dump-autoload step}';

    protected $description = 'Create a nested per-client package scaffold in packages/{Client}/{Package}';

    public function __construct(private readonly Filesystem $files, private readonly Composer $composer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $client = $this->normalizeName((string) $this->argument('client'));
        $package = $this->normalizeName((string) $this->argument('package'));

        if ($client === null || $package === null) {
            $this->error('Invalid client/package name. Use letters and numbers (e.g. Acme Shop).');
            return self::FAILURE;
        }

        if (in_array($client, ['Core', 'Commander'], true)) {
            $this->error("Client name \"{$client}\" is reserved (framework).");
            return self::FAILURE;
        }

        $namespace = "{$client}\\{$package}"; // Acme\Shop  (path del namespace)
        $class = "{$client}{$package}";       // AcmeShop    (prefijo de clase, N2)

        $corePath = base_path('packages/Core');
        $targetPath = base_path("packages/{$client}/{$package}");

        if (!$this->files->isDirectory($corePath)) {
            $this->error('Core package path not found: ' . $corePath);
            return self::FAILURE;
        }

        if ($this->files->exists($targetPath)) {
            if (!$this->option('force')) {
                $this->error("Package already exists at {$targetPath}. Use --force to overwrite.");
                return self::FAILURE;
            }

            $this->files->deleteDirectory($targetPath);
        }

        $this->files->ensureDirectoryExists($targetPath);
        $this->cloneCoreGitkeepMarkedDirectories($corePath, $targetPath);
        $this->writePackageFiles($namespace, $class, $targetPath);
        $this->syncComposerAutoload($client, $package);
        $this->dumpAutoload();

        $slug = str_replace('_', '-', Str::snake($package));
        $sub = str_replace('_', '-', Str::snake($client));
        $this->info("Package {$class} created at {$targetPath}");
        $this->line("Provisiónalo desde el Commander (cliente '{$sub}' + plan del package {$package}) para servirlo en {$sub}.<BASE_DOMAIN>/api/v1/{$slug} (crea su BD).");

        return self::SUCCESS;
    }

    /**
     * Regenerate the Composer autoloader so the new package's PSR-4 namespaces resolve immediately.
     * Best-effort: the scaffold already succeeded, so a failing dump only warns (run it by hand then).
     */
    private function dumpAutoload(): void
    {
        if ($this->option('no-dump')) {
            $this->line('Skipping composer dump-autoload (--no-dump). Run it manually before using the package.');
            return;
        }

        $this->line('Running composer dump-autoload...');

        try {
            $this->composer->dumpAutoloads();
            $this->line('Composer autoload regenerated.');
        } catch (\Throwable $e) {
            $this->warn('composer dump-autoload failed: ' . $e->getMessage());
            $this->warn('Run "composer dump-autoload" manually before using the package.');
        }
    }

    private function normalizeName(string $raw): ?string
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

    private function cloneCoreGitkeepMarkedDirectories(string $corePath, string $targetPath): void
    {
        $this->cloneGitkeepMarkedDirectory($corePath, $targetPath);
    }

    private function cloneGitkeepMarkedDirectory(string $sourceDirectory, string $targetDirectory): void
    {
        $sourceGitkeepPath = $sourceDirectory . DIRECTORY_SEPARATOR . '.gitkeep';

        if ($this->files->exists($sourceGitkeepPath)) {
            $this->files->ensureDirectoryExists($targetDirectory);
            $this->files->copy($sourceGitkeepPath, $targetDirectory . DIRECTORY_SEPARATOR . '.gitkeep');
        }

        $childDirectories = $this->files->directories($sourceDirectory);
        sort($childDirectories, SORT_STRING);

        foreach ($childDirectories as $childSourceDirectory) {
            $childName = basename($childSourceDirectory);
            $childTargetDirectory = $targetDirectory . DIRECTORY_SEPARATOR . $childName;

            $this->cloneGitkeepMarkedDirectory($childSourceDirectory, $childTargetDirectory);
        }
    }

    private function writePackageFiles(string $namespace, string $class, string $targetPath): void
    {
        $files = [
            "/app/Providers/{$class}ServiceProvider.php" => 'provider.tpl',
            "/app/Http/Controllers/{$class}AuthController.php" => 'auth-controller.tpl',
            "/app/Http/Controllers/{$class}UsersController.php" => 'users-controller.tpl',
            '/routes/api.php' => 'routes-api.tpl',
            "/database/seeders/{$class}DatabaseSeeder.php" => 'database-seeder.tpl',
            '/config/scramble.php' => 'config-scramble.tpl',
            '/config/sanctum.php' => 'config-sanctum.tpl',
        ];
        $variables = [
            'namespace' => $namespace,
            'class' => $class,
        ];
        $renderer = new CoreTemplateRendererSupport(
            $this->files,
            base_path('packages/Core/resources/templates/make-package'),
            $this
        );

        foreach ($files as $relativePath => $template) {
            $fullPath = $targetPath . $relativePath;
            $this->files->ensureDirectoryExists(dirname($fullPath));
            $renderer->write($fullPath, $template, $variables);
        }
    }

    private function syncComposerAutoload(string $client, string $package): void
    {
        $composerPath = base_path('composer.json');

        if (!$this->files->exists($composerPath)) {
            $this->warn('composer.json not found. Skipping autoload sync.');
            return;
        }

        $raw = $this->files->get($composerPath);

        try {
            $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->warn('Invalid composer.json JSON: ' . $exception->getMessage());
            return;
        }

        if (!is_array($composer)) {
            $this->warn('Invalid composer.json content. Skipping autoload sync.');
            return;
        }

        $composer['autoload'] ??= [];
        $composer['autoload']['psr-4'] ??= [];

        $packageNamespace = "Packages\\{$client}\\{$package}\\";
        $seedersNamespace = "Packages\\{$client}\\{$package}\\Database\\Seeders\\";

        $composer['autoload']['psr-4'][$packageNamespace] = "packages/{$client}/{$package}/app/";
        $composer['autoload']['psr-4'][$seedersNamespace] = "packages/{$client}/{$package}/database/seeders/";

        ksort($composer['autoload']['psr-4'], SORT_STRING);

        $this->files->put(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $this->line("Updated composer autoload for package {$client}/{$package}.");
    }
}
