<?php

namespace Packages\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Dedoc\Scramble\Scramble;
use Packages\Core\Scramble\DocumentTransformers\CoreBearerSecuritySchemeDocumentTransformer;
use Packages\Core\Scramble\OperationTransformers\CoreSanctumOperationSecurityTransformer;

class CoreScrambleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();

        $this->configureScrambleSecurity();
        $this->configureScrambleRoutesFilter();
    }

    public function boot(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        View::prependNamespace('scramble', base_path('packages/Core/resources/views/vendor/rapidoc'));

        $docsConfig = $this->app->make('config')->get('scramble.docs', []);
        $uiPath = $docsConfig['ui_path'] ?? 'docs';
        $jsonPath = $docsConfig['json_path'] ?? 'docs.json';

        if (is_string($uiPath) && $uiPath !== '') {
            Scramble::registerUiRoute($uiPath);
        }

        if (is_string($jsonPath) && $jsonPath !== '') {
            Scramble::registerJsonSpecificationRoute($jsonPath);
        }
    }

    private function configureScrambleRoutesFilter(): void
    {
        Scramble::routes(function ($route): bool {
            $prefix = config('scramble.api_path', 'api');
            $domain = config('scramble.api_domain');

            $isBaseMatching = ! $prefix || Str::startsWith($route->uri, $prefix);
            if (! $isBaseMatching) {
                return false;
            }

            if ($domain && $route->getDomain() !== $domain) {
                return false;
            }

            // Los endpoints /control son maquina-a-maquina (Commander): fuera de docs publicas
            if (Str::is('api/v1/control*', $route->uri)) {
                return false;
            }

            $docsConfig = config('scramble.docs', []);
            $docsPaths = array_filter([
                $docsConfig['ui_path'] ?? null,
                $docsConfig['json_path'] ?? null,
            ]);

            return ! in_array($route->uri, $docsPaths, true);
        });
    }

    private function configureScrambleSecurity(): void
    {
        Scramble::configure()->withDocumentTransformers([
            CoreBearerSecuritySchemeDocumentTransformer::class,
        ]);

        Scramble::configure()->withOperationTransformers([
            CoreSanctumOperationSecurityTransformer::class,
        ]);
    }
}
