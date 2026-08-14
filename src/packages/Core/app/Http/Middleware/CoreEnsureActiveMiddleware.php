<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill-switch del deploy: lee el estado de la instancia (cacheado) y corta con 402
 * cuando el estado efectivo es suspended. Grace deja pasar con headers de
 * aviso. Si no puede comprobar el estado de un tenant, falla cerrado con 503.
 */
class CoreEnsureActiveMiddleware
{
    public function __construct(
        private readonly CoreInstanceStateService $stateService,
        private readonly CoreActiveInstanceSupport $active,
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

        if ($effective === CoreServiceStatusEnum::Suspended) {
            return response()->json([
                'status' => 'suspended',
                'reason' => $state->reason,
                'message' => $state->message ?? 'This API deployment is currently suspended.',
            ], 402)->header('X-Instance-Status', 'suspended');
        }

        $response = $next($request);

        if ($effective === CoreServiceStatusEnum::Grace) {
            $response->headers->set('X-Instance-Status', 'grace');

            if ($state->graceUntil !== null) {
                $response->headers->set('X-Instance-Grace-Until', $state->graceUntil->toIso8601String());
            }

            if ($state->message !== null) {
                $response->headers->set('X-Instance-Message', $state->message);
            }
        }

        return $response;
    }
}
