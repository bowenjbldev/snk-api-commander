<?php

namespace Packages\Core\Console\Concerns;

use function Illuminate\Filesystem\join_paths;

trait CorePackageAwareMigrationGeneratorSupport
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions(): array
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function createBaseMigration($table)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::createBaseMigration($table);
        }

        $migrationsPath = $packageBasePath . '/database/migrations';
        $this->files->ensureDirectoryExists($migrationsPath);

        return $this->laravel['migration.creator']->create(
            'create_' . $table . '_table',
            $migrationsPath
        );
    }

    protected function migrationExists($table)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::migrationExists($table);
        }

        return count($this->files->glob(
            join_paths($packageBasePath . '/database/migrations', '*_*_*_*_create_' . $table . '_table.php')
        )) !== 0;
    }
}
