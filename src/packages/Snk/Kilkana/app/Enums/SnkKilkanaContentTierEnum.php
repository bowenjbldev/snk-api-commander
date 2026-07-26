<?php

namespace Packages\Snk\Kilkana\Enums;

use Packages\Snk\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Cuanto de una pieza puede leer quien no paga.
 *
 * Es dato de SEO, no solo de negocio: un buscador tiene que poder indexar el texto completo
 * de un articulo cerrado sin que eso sea encubrimiento, y la unica forma de que no lo sea es
 * DECLARARLO. En el structured data se emite como `isAccessibleForFree` mas la parte cerrada;
 * servirle a un buscador el texto entero y al lector un muro, sin declararlo, es justo lo que
 * se penaliza.
 *
 * Este campo dice SI la pieza esta cerrada. Donde corta el muro lo dice el bloque `paywall`
 * dentro del cuerpo, y son dos cosas distintas: una pieza medida no necesita bloque —se mide
 * entera— y una cerrada sin bloque no sabria por donde partirse.
 */
enum SnkKilkanaContentTierEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Abierta. Nadie tiene que pagar ni registrarse. */
    case Free = 'free';

    /** Contador: se lee entera hasta agotar la cuota del mes. */
    case Metered = 'metered';

    /** Cerrada: se corta por el bloque `paywall` del cuerpo. */
    case Locked = 'locked';

    /** Lo que va a `isAccessibleForFree` del structured data. */
    public function isAccessibleForFree(): bool
    {
        return $this === self::Free;
    }
}
