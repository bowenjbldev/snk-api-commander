<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Services\CorePackageStateService;
use Packages\Core\Support\CoreActivePackageSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill-switch del deploy: lee el estado del package (commander_packages, cacheado) y corta con 402
 * cuando el estado efectivo es suspended. Grace deja pasar con headers de
 * aviso. Si no puede comprobar el estado de un tenant, falla cerrado con 503.
 */
class CoreEnsureActiveMiddleware
{
    public function __construct(
        private readonly CorePackageStateService $stateService,
        private readonly CoreActivePackageSupport $active,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach ((array) config('core.state.exempt_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        // Health, Core y Commander no representan una instancia tenant.
        if ($this->active->id() === null) {
            return $next($request);
        }

        try {
            $state = $this->stateService->current();
            $effective = $state->effectiveStatus();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'Tenant status could not be verified.',
            ], 503);
        }

        if ($effective === CorePackageStatusEnum::Suspended) {
            return response()->json([
                'status' => 'suspended',
                'reason' => $state->reason,
                'message' => $state->message ?? 'This API deployment is currently suspended.',
            ], 402)->header('X-Package-Status', 'suspended');
        }

        $response = $next($request);

        if ($effective === CorePackageStatusEnum::Grace) {
            $response->headers->set('X-Package-Status', 'grace');

            if ($state->graceUntil !== null) {
                $response->headers->set('X-Package-Grace-Until', $state->graceUntil->toIso8601String());
            }

            if ($state->message !== null) {
                $response->headers->set('X-Package-Message', $state->message);
            }
        }

        return $response;
    }
}
