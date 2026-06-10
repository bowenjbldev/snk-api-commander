<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreModelMakeCommand extends ModelMakeCommand
{
    use CorePackageAwareGeneratorSupport;

    protected function createFactory()
    {
        $factory = $this->coreModelEntityName();

        $this->call('make:factory', $this->corePassPackageToCalledCommand([
            'name' => "{$factory}Factory",
            '--model' => $this->qualifyClass($this->getNameInput()),
        ]));
    }

    protected function createMigration()
    {
        $table = Str::snake(Str::pluralStudly($this->coreModelEntityName()));

        if ($this->option('pivot')) {
            $table = Str::singular($table);
        }

        $this->call('make:migration', $this->corePassPackageToCalledCommand([
            'name' => "create_{$table}_table",
            '--create' => $table,
        ]));
    }

    protected function createSeeder()
    {
        $seeder = $this->coreModelEntityName();

        $this->call('make:seeder', $this->corePassPackageToCalledCommand([
            'name' => "{$seeder}Seeder",
        ]));
    }

    protected function createController()
    {
        $controller = $this->coreModelEntityName();
        $modelName = $this->qualifyClass($this->getNameInput());

        $this->call('make:controller', $this->corePassPackageToCalledCommand(array_filter([
            'name' => "{$controller}Controller",
            '--model' => $this->option('resource') || $this->option('api') ? $modelName : null,
            '--api' => $this->option('api'),
            '--requests' => $this->option('requests') || $this->option('all'),
            '--test' => $this->option('test'),
            '--pest' => $this->option('pest'),
        ])));
    }

    protected function createFormRequests()
    {
        $request = $this->coreModelEntityName();

        $this->call('make:request', $this->corePassPackageToCalledCommand([
            'name' => "Store{$request}Request",
        ]));

        $this->call('make:request', $this->corePassPackageToCalledCommand([
            'name' => "Update{$request}Request",
        ]));
    }

    protected function createPolicy()
    {
        $policy = $this->coreModelEntityName();

        $this->call('make:policy', $this->corePassPackageToCalledCommand([
            'name' => "{$policy}Policy",
            '--model' => $this->qualifyClass($this->getNameInput()),
        ]));
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        if ($this->corePackageOption() !== null) {
            return $rootNamespace . '\\Models';
        }

        return parent::getDefaultNamespace($rootNamespace);
    }

    private function coreModelEntityName(): string
    {
        return $this->coreEntityNameFromGeneratorName($this->getNameInput(), 'Model');
    }
}
