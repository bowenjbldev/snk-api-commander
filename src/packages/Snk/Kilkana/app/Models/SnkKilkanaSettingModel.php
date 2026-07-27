<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

class SnkKilkanaSettingModel extends Model
{
    protected $table = 'kilkana_settings';

    protected $guarded = [];

    /**
     * La clave se escribe, no se genera: esta tabla tiene una fila y su id es 1.
     *
     * Sin esto, tras insertar con id explícito Eloquent lee `lastInsertId()` —que sin
     * autoincremento devuelve 0— y se lo asigna al modelo.
     */
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * Los mismos valores por defecto que la columna, pero del lado de PHP.
     *
     * Los pone la base, así que la fila recién creada por `firstOrCreate` no los conocería y
     * `version` saldría a 0 en la respuesta del primer guardado — justo el número con el que
     * el cliente decide si hay conflicto.
     */
    protected $attributes = [
        'onboarded' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            // Lo único que sigue siendo JSON, y con razón: es un mapa de variante → token →
            // valor, abierto por definición y opaco para el servidor (lo normaliza el cliente).
            // El resto de los ajustes son columnas: se conocen, se tipan y se validan.
            'theme_overrides' => 'array',
            'onboarded' => 'boolean',
            'version' => 'integer',
        ];
    }
}
