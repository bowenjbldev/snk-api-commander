<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;

class SnkKilkanaSettingService
{
    private const SINGLETON_ID = 1;

    private const DEFAULTS = [
        'theme' => null,
        'theme_overrides' => [],
        'tenant' => ['name' => null, 'tagline' => null, 'city' => null],
        'onboarded' => false,
    ];

    public function get(): SnkKilkanaSettingModel
    {
        return SnkKilkanaSettingModel::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['settings' => self::DEFAULTS],
        );
    }

    /**
     * Guarda si nadie se ha adelantado. Misma forma que la composición, y por el mismo motivo.
     *
     * Comprobar la version fuera de la transacción no detecta nada: dos personas guardando los
     * ajustes a la vez leen la misma, las dos pasan la comprobación y la segunda pisa a la
     * primera con un 200 en la mano. Aquí duele además de otra manera —los ajustes se mezclan
     * sobre lo que ya había, así que una escritura perdida se lleva por delante claves que ni
     * siquiera se estaban tocando—.
     *
     * `get()` va fuera porque crea la fila la primera vez, y no se bloquea lo que no existe.
     *
     * @return array{row: SnkKilkanaSettingModel, conflict: bool}
     */
    public function update(array $data): array
    {
        $this->get();

        return DB::transaction(function () use ($data) {
            $row = SnkKilkanaSettingModel::query()
                ->whereKey(self::SINGLETON_ID)
                ->lockForUpdate()
                ->first();

            if (isset($data['version']) && (int) $data['version'] !== (int) $row->version) {
                return ['row' => $row, 'conflict' => true];
            }

            unset($data['version']);
            $settings = $row->settings ?? [];
            if (array_key_exists('tenant', $data) && is_array($data['tenant'])) {
                $data['tenant'] = array_merge($settings['tenant'] ?? [], $data['tenant']);
            }
            $row->settings = array_merge($settings, $data);
            $row->version = (int) $row->version + 1;
            $row->save();

            return ['row' => $row, 'conflict' => false];
        });
    }

    public function payload(SnkKilkanaSettingModel $row): array
    {
        $settings = array_merge(self::DEFAULTS, $row->settings ?? []);
        $settings['tenant'] = array_merge(self::DEFAULTS['tenant'], $settings['tenant'] ?? []);
        $settings['version'] = (int) $row->version;

        return $settings;
    }
}
