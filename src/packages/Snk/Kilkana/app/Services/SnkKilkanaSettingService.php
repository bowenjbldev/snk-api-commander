<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;

/**
 * Los ajustes del periodico: una sola fila, con columnas de verdad.
 *
 * Fue una bolsa JSON, y la bolsa cobraba el precio de la flexibilidad sin darla: el
 * controlador ya enumera cada clave que acepta, asi que nunca hubo claves arbitrarias. A
 * cambio se perdian los tipos —`onboarded` guardado como lo que fuera— y habia que fusionar a
 * mano, con un caso especial para `tenant` que alguien tenia que acordarse de replicar en el
 * siguiente ajuste anidado. El dia que se olvidara, una actualizacion parcial habria borrado
 * las claves hermanas en silencio.
 *
 * El JSON que ve la app NO cambia: sigue llegando `tenant` anidado. Lo que cambia es que
 * debajo hay columnas, y el mapeo plano ↔ anidado esta escrito aqui en vez de deducirse.
 */
class SnkKilkanaSettingService
{
    private const SINGLETON_ID = 1;

    /** Los tres campos de la identidad, planos en la base y anidados en la API. */
    private const TENANT = ['name', 'tagline', 'city'];

    public function get(): SnkKilkanaSettingModel
    {
        return SnkKilkanaSettingModel::firstOrCreate(['id' => self::SINGLETON_ID]);
    }

    /**
     * Guarda si nadie se ha adelantado. Misma forma que la composicion, y por el mismo motivo.
     *
     * Comprobar la version fuera de la transaccion no detecta nada: dos personas guardando los
     * ajustes a la vez leen la misma, las dos pasan la comprobacion y la segunda pisa a la
     * primera con un 200 en la mano.
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

            $row->fill($this->columnsFrom($data));
            $row->version = (int) $row->version + 1;
            $row->save();

            return ['row' => $row, 'conflict' => false];
        });
    }

    public function payload(SnkKilkanaSettingModel $row): array
    {
        return [
            'theme' => $row->theme,
            'theme_overrides' => $row->theme_overrides ?? [],
            'tenant' => [
                'name' => $row->tenant_name,
                'tagline' => $row->tenant_tagline,
                'city' => $row->tenant_city,
            ],
            'onboarded' => (bool) $row->onboarded,
            'version' => (int) $row->version,
        ];
    }

    /**
     * Del JSON anidado de la API a las columnas planas.
     *
     * Solo llega lo que venia: un guardado parcial toca sus campos y deja los demas donde
     * estaban. Eso antes exigia fusionar a mano; ahora es lo que hace `fill` por su cuenta.
     */
    private function columnsFrom(array $data): array
    {
        $columnas = collect($data)->only(['theme', 'theme_overrides', 'onboarded'])->all();

        $tenant = is_array($data['tenant'] ?? null) ? $data['tenant'] : [];
        foreach (self::TENANT as $campo) {
            if (array_key_exists($campo, $tenant)) {
                $columnas["tenant_{$campo}"] = $tenant[$campo];
            }
        }

        return $columnas;
    }
}
