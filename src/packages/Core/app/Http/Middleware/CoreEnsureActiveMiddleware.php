<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Services\CorePackageStateService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill-switch del deploy: lee core_package_state (cacheado) y corta con 402
 * cuando el estado efectivo es suspended. Grace deja pasar con headers de
 * aviso. Fail-open: cualquier fallo interno (tabla ausente pre-migrate,
 * cache caida) equivale a active.
 */
class CoreEnsureActiveMiddleware
{
    public function __construct(private readonly CorePackageStateService $stateService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        foreach ((array) config('core.state.exempt_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        try {
            $state = $this->stateService->current();
            $effective = $state->effectiveStatus();
        } catch (\Throwable $e) {
            report($e);

            return $next($request);
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
