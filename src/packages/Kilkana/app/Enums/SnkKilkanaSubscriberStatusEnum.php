<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * En qué punto está alguien respecto al boletín, y la ÚNICA definición de los tres estados.
 *
 * `status` es TODO el estado: no hay marca de tiempo de verificación, porque `confirmed` ya
 * dice que lo está y dos representaciones del mismo hecho acaban desincronizadas.
 *
 * Orden de ciclo de vida: se entra por `pending`, se confirma, y de ahi se puede salir.
 */
enum SnkKilkanaSubscriberStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Dio su correo y todavía no ha confirmado que es suyo. No recibe nada. */
    case Pending = 'pending';

    /** Confirmado. Es el único estado al que se envía. */
    case Confirmed = 'confirmed';

    /** Se dio de baja. La fila SE QUEDA: es la prueba de que pidió no recibir más. */
    case Unsubscribed = 'unsubscribed';

    /** Si se le puede enviar el boletín. */
    public function receivesMail(): bool
    {
        return $this === self::Confirmed;
    }
}
