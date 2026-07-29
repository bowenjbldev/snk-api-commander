<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Snk\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;

// Suscriptor del boletin. Lo que significa cada estado lo dice SnkKilkanaSubscriberStatusEnum,
// y de donde salio su correo, SnkKilkanaConsentSourceEnum.
class SnkKilkanaSubscriberModel extends Model
{
    protected $table = 'kilkana_subscribers';

    protected $guarded = [];

    protected $casts = [
        'status' => SnkKilkanaSubscriberStatusEnum::class,
        'consent_source' => SnkKilkanaConsentSourceEnum::class,
        'consent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $suscriptor) {
            // Un alta que no dice de donde salio la esta tecleando la redaccion en el panel:
            // es el unico camino de creacion que no lo declara —el formulario publico pone
            // `public_form` y el seeder `import`—. Aqui y no en el controlador porque el
            // `store()` base crea con lo validado y no admite retoques en medio.
            //
            // Techo: el dia que haya un comando de importacion, tiene que declarar su origen o
            // quedara etiquetado como tecleado a mano, que es una prueba falsa, no una ausente.
            $suscriptor->consent_source ??= SnkKilkanaConsentSourceEnum::Admin;
            $suscriptor->consent_at ??= now();
        });
    }
}
