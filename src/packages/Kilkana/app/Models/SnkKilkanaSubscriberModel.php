<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;

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
            // Un alta que no dice de dónde salió procede del flujo administrativo:
            // es el único camino de creación que no lo declara —el formulario público pone
            // `public_form`, y el seeder y la importacion `import`—. Aqui y no en el
            // controlador porque el `store()` base crea con lo validado y no admite retoques
            // en medio.
            $suscriptor->consent_source ??= SnkKilkanaConsentSourceEnum::Admin;
            $suscriptor->consent_at ??= now();
        });
    }
}
