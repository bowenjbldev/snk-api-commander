<?php

namespace Packages\Snk\Folio\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Folio\Models\SnkFolioSettingModel;

/**
 * Ajustes del workspace (singleton, patron commander_settings): tema activo, overrides de tema,
 * identidad del periodico (tenant) y flag de onboarding. Bolsa JSON expuesta/aceptada PLANA con
 * merge parcial. theme_overrides es opaco para el server: la normalizacion fina (hex, rangos)
 * vive en el cliente (overrides.ts).
 */
#[Group('Settings')]
class SnkFolioSettingsController extends Controller
{
    private const SINGLETON_ID = 1;

    private const DEFAULTS = [
        'theme' => null,            // null => el cliente resuelve su default (primer tema del catalogo)
        'theme_overrides' => [],
        'tenant' => ['name' => null, 'tagline' => null, 'city' => null],
        'onboarded' => false,
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->singleton()));
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

        $row = $this->singleton();

        // Concurrencia optimista opcional (como folio_layouts): si el cliente manda
        // la versión que leyó y otro guardado ganó, 409 en vez de last-write-wins.
        if (isset($data['version']) && (int) $data['version'] !== (int) $row->version) {
            return response()->json(['error' => 'Version conflict', 'version' => $row->version], 409);
        }
        unset($data['version']);
        // Merge parcial: solo pisa las claves enviadas; tenant tambien se mergea por dentro
        // para que {tenant:{name}} no borre tagline/city.
        $settings = $row->settings ?? [];
        if (array_key_exists('tenant', $data) && is_array($data['tenant'])) {
            $data['tenant'] = array_merge($settings['tenant'] ?? [], $data['tenant']);
        }
        $row->settings = array_merge($settings, $data);
        $row->version = (int) $row->version + 1;
        $row->save();

        return response()->json($this->payload($row));
    }

    private function payload(SnkFolioSettingModel $row): array
    {
        $settings = array_merge(self::DEFAULTS, $row->settings ?? []);
        $settings['tenant'] = array_merge(self::DEFAULTS['tenant'], $settings['tenant'] ?? []);
        $settings['version'] = (int) $row->version;

        return $settings;
    }

    private function singleton(): SnkFolioSettingModel
    {
        return SnkFolioSettingModel::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['settings' => self::DEFAULTS],
        );
    }
}
