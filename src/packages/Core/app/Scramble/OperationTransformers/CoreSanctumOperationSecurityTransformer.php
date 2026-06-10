<?php

namespace Packages\Core\Scramble\OperationTransformers;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CoreSanctumOperationSecurityTransformer implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($operation->security === []) {
            return;
        }

        $middlewares = collect($routeInfo->route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware))
            ->values();

        if (! $this->hasAuthGuard($middlewares, 'sanctum')) {
            return;
        }

        $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
    }

    private function hasAuthGuard(Collection $middlewares, string $guard): bool
    {
        return $middlewares->contains(function (string $middleware) use ($guard): bool {
            if (! Str::startsWith($middleware, 'auth:')) {
                return false;
            }

            $guards = explode(',', (string) Str::after($middleware, 'auth:'));
            $guards = array_map('trim', $guards);

            return in_array($guard, $guards, true);
        });
    }
}
