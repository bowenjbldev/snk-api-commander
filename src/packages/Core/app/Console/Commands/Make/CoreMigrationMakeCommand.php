<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreMigrationMakeCommand extends MigrateMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected $signature = 'make:migration {name : The name of the migration}
        {--create= : The table to be created}
        {--table= : The table to migrate}
        {--path= : The location where the migration file should be created}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--fullpath : Output the full path of the migration (Deprecated)}
        {--package= : Target package (e.g. Rebooking)}';

    public function handle()
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath !== null && $this->input->getOption('path') === null) {
            $path = "packages/{$this->corePackageOption()}/database/migrations";
            $this->input->setOption('path', $path);
            app('files')->ensureDirectoryExists(base_path($path));
        }

        parent::handle();
    }
}
