<?php

namespace Packages\Core\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        $docsConfig = $this->app->make('config')->get('scramble.docs', []);
        $jsonPath = $docsConfig['json_path'] ?? 'docs.json';

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

            $docsConfig = config('scramble.docs', []);
            $docsPaths = array_filter([
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
