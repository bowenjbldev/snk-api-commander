<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Kilkana\Services\SnkKilkanaPlanService;

/**
 * El plan del periódico, de solo lectura.
 *
 * No hay `update`: un inquilino mira su consumo, no se cambia de plan desde su propia
 * redacción. Eso pasa por Commander, que es quien factura, y por `instances/{id}/change-plan`.
 */
#[Group('Plan')]
class SnkKilkanaPlanController extends Controller
{
    public function __construct(private readonly SnkKilkanaPlanService $plan) {}

    public function show(): JsonResponse
    {
        $payload = $this->plan->payload();

        return $payload === null
            ? response()->json(['error' => 'Not Found'], 404)
            : response()->json($payload);
    }
}
