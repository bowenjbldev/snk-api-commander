<?php

namespace Packages\Commander\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderSettingModel;

/**
 * Perfil + preferencias del operador (singleton, BD commander). El default_grace_hours alimenta
 * el grace real: el SPA lo usa para calcular grace_until y el backend lo respeta tal cual. La ruta
 * va bajo auth:sanctum, asi que solo un operador autenticado llega aqui.
 */
#[Group('Settings')]
class CommanderSettingsController extends Controller
{
    private const KEY = 'operator';

    public function show(): JsonResponse
    {
        return response()->json($this->singleton());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_grace_hours' => 'nullable|integer|min:1|max:720',
            'operator_name' => 'nullable|string|max:255',
            'operator_email' => 'nullable|email|max:255',
            'notify_on_suspend' => 'nullable|boolean',
            'notify_on_overdue' => 'nullable|boolean',
        ]);

        $settings = $this->singleton();
        $settings->fill($data);
        $settings->save();

        return response()->json($settings);
    }

    /** Crea la fila singleton la primera vez, sembrando el grace por defecto desde la config de Core. */
    private function singleton(): CommanderSettingModel
    {
        return CommanderSettingModel::firstOrCreate(
            ['id' => self::KEY],
            [
                'default_grace_hours' => (int) config('core.quotas.grace_hours', 72),
                'notify_on_suspend' => true,
                'notify_on_overdue' => true,
            ],
        );
    }
}
