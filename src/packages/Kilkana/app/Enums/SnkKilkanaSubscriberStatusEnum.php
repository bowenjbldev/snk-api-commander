<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * En que punto esta alguien respecto al boletin, y la UNICA definicion de los tres estados.
 *
 * `status` es TODO el estado: no hay marca de tiempo de verificacion, porque `confirmed` ya
 * dice que lo esta y dos representaciones del mismo hecho acaban desincronizadas.
 *
 * Orden de ciclo de vida: se entra por `pending`, se confirma, y de ahi se puede salir.
 */
enum SnkKilkanaSubscriberStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Dio su correo y todavia no ha confirmado que es suyo. No recibe nada. */
    case Pending = 'pending';

    /** Confirmado. Es el unico estado al que se envia. */
    case Confirmed = 'confirmed';

    /** Se dio de baja. La fila SE QUEDA: es la prueba de que pidio no recibir mas. */
    case Unsubscribed = 'unsubscribed';

    /** Si se le puede enviar el boletin. */
    public function receivesMail(): bool
    {
        return $this === self::Confirmed;
    }
}
