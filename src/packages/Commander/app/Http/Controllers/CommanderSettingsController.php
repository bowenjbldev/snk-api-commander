<?php

namespace Packages\Commander\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderSettingModel;

/**
 * Perfil + preferencias del operador (singleton, BD commander). Se guardan como UNA bolsa JSON
 * (`commander_settings.settings`) pero la API las expone/acepta PLANAS: show()/update() despliegan y
 * mergean con los defaults. default_grace_hours se siembra desde core.quotas.grace_hours y se guarda,
 * pero el cálculo de grace_until lee esa config y no esta preferencia. La
 * ruta va bajo auth:sanctum, asi que solo un operador autenticado llega aqui.
 */
#[Group('Settings')]
class CommanderSettingsController extends Controller
{
    private const SINGLETON_ID = 1;

    /** Defaults de cada preferencia; garantizan que la respuesta plana lleva siempre las 5 claves. */
    private const DEFAULTS = [
        'operator_name' => null,
        'operator_email' => null,
        'default_grace_hours' => 72,
        'notify_on_suspend' => true,
        'notify_on_overdue' => true,
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->singleton()));
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

        $row = $this->singleton();
        // Merge parcial: solo pisa las claves enviadas, conserva el resto de la bolsa.
        $row->settings = array_merge($row->settings ?? [], $data);
        $row->save();

        return response()->json($this->payload($row));
    }

    /** Aplana la bolsa JSON a las 5 claves que forman el contrato de la API. */
    private function payload(CommanderSettingModel $row): array
    {
        return array_merge(self::DEFAULTS, $row->settings ?? []);
    }

    /** Crea la fila singleton la primera vez, sembrando el grace por defecto desde la config de Core. */
    private function singleton(): CommanderSettingModel
    {
        return CommanderSettingModel::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['settings' => array_merge(self::DEFAULTS, [
                'default_grace_hours' => (int) config('core.quotas.grace_hours', 72),
            ])],
        );
    }
}
