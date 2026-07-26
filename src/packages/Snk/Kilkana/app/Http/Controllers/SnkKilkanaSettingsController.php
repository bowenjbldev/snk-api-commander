<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Services\SnkKilkanaSettingService;

/**
 * Ajustes del workspace (singleton, patron commander_settings): tema activo, overrides de tema,
 * identidad del periodico (tenant) y flag de onboarding. Bolsa JSON expuesta/aceptada PLANA con
 * merge parcial. theme_overrides es opaco para el server: la normalizacion fina (hex, rangos)
 * vive en el cliente (overrides.ts).
 */
#[Group('Settings')]
class SnkKilkanaSettingsController extends Controller
{
    public function __construct(private readonly SnkKilkanaSettingService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->payload($this->settings->get()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => 'nullable|string|max:64',
            'theme_overrides' => 'nullable|array',
            'tenant' => 'nullable|array',
            'tenant.name' => 'nullable|string|max:255',
            'tenant.tagline' => 'nullable|string|max:255',
            'tenant.city' => 'nullable|string|max:255',
            'onboarded' => 'nullable|boolean',
            'version' => 'nullable|integer|min:1',
        ]);

        $result = $this->settings->update($data);
        if ($result['conflict']) {
            return response()->json(['error' => 'Version conflict', 'version' => $result['row']->version], 409);
        }

        return response()->json($this->settings->payload($result['row']));
    }
}
