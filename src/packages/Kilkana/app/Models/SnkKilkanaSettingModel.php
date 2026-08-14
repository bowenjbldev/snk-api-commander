<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * El mismo valor por defecto que la columna, pero del lado de PHP.
     *
     * Lo pone la base, así que la fila recién creada por `firstOrCreate` no lo conocería y
     * `onboarded` saldría nulo en la respuesta del primer guardado.
     */
    protected $attributes = [
        'onboarded' => false,
    ];

    protected function casts(): array
    {
        return [
            'onboarded' => 'boolean',
        ];
    }

    /**
     * La piel que viste el periódico.
     *
     * Fue una cadena (`theme`) más un mapa JSON de retoques (`theme_overrides`). Las dos eran
     * una tabla de variantes escrita dentro de dos columnas, así que ahora son una clave
     * foránea: la base impide vestir una variante que no existe y protege con RESTRICT la que
     * está puesta.
     *
     * Nula solo en una instalación recién sembrada; el contrato cae a la primera variante que
     * envía el producto.
     */
    public function themeVariant(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaThemeVariantModel::class, 'theme_variant_id');
    }
}
