<?php

namespace Packages\Snk\Kilkana\Services;

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

    /** @return array{row: SnkKilkanaSettingModel, conflict: bool} */
    public function update(array $data): array
    {
        $row = $this->get();
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
    }

    public function payload(SnkKilkanaSettingModel $row): array
    {
        $settings = array_merge(self::DEFAULTS, $row->settings ?? []);
        $settings['tenant'] = array_merge(self::DEFAULTS['tenant'], $settings['tenant'] ?? []);
        $settings['version'] = (int) $row->version;

        return $settings;
    }
}
