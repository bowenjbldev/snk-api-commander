<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Cuánto de una pieza puede leer quien no paga.
 *
 * Es dato de SEO, no solo de negocio: un buscador tiene que poder indexar el texto completo
 * de un artículo cerrado sin que eso sea encubrimiento, y la única forma de que no lo sea es
 * DECLARARLO. En el structured data se emite como `isAccessibleForFree` más la parte cerrada;
 * servirle a un buscador el texto entero y al lector un muro, sin declararlo, es justo lo que
 * se penaliza.
 *
 * Este campo dice SI la pieza está cerrada. Dónde corta el muro lo dice el bloque `paywall`
 * dentro del cuerpo, y son dos cosas distintas: una pieza medida no necesita bloque —se mide
 * entera— y una cerrada sin bloque no sabría por dónde partirse.
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
