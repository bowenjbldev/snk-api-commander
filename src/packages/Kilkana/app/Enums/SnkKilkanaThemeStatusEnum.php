<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * El ciclo de vida de un tema del catálogo.
 *
 * Un tema no nace a la venta: se prepara, se publica y algún día se retira. Sin esta columna
 * solo habría dos situaciones —existe y se ofrece, o no existe— y eso obligaría a terminar un
 * tema antes de poder sembrarlo en ninguna parte.
 *
 * El orden de declaración es el del ciclo de vida, así que `ORDER BY status` recorre la vida
 * del tema en vez de ordenar alfabéticamente. Añadir un estado al final es barato; insertarlo
 * en medio renumera los existentes.
 */
enum SnkKilkanaThemeStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** En preparación. No se ofrece en el catálogo, aunque esté sembrado. */
    case Draft = 'draft';

    /** A la venta: es lo único que devuelve la API del catálogo. */
    case Published = 'published';

    /**
     * Retirado: deja de ofrecerse, pero NO se borra.
     *
     * Quien ya lo viste sigue vistiéndolo, porque al adoptarlo se quedó con una copia congelada
     * del spec. Retirar un tema es dejar de venderlo, no romper los periódicos que lo llevan.
     */
    case Deprecated = 'deprecated';
}
