<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Si una sección existe para el lector o solo para la redacción.
 *
 * Sin el estado intermedio, abrir una mesa nueva no se puede representar: o la sección existe
 * y sale ya en la navegación, o no existe. Una redacción no trabaja así —se crea la mesa, se
 * le asignan piezas, y se lanza cuando hay con qué abrirla—.
 *
 * Ojo con no confundirlo con una sección viva y VACÍA, que es otro estado y también es
 * legítimo: `motor` nace sin artículos a propósito en el juego de datos.
 *
 * Tampoco es `noindex`, que es la otra mitad: `noindex` dice «se ve pero no la anuncies a los
 * buscadores»; esto dice «todavía no se ve».
 *
 * Orden de ciclo de vida: se entra por `draft` y se sale lanzando. `hidden` no es un paso de
 * ese camino sino un destino: una sección que se retira del menú sin retirar sus URLs.
 */
enum SnkKilkanaCategoryStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** En preparación. No sale al público, y no admite piezas publicadas. */
    case Draft = 'draft';

    /** Lanzada: navegable, con página propia y en el catálogo público. */
    case Live = 'live';

    /**
     * Alcanzable por su URL y fuera del menú.
     *
     * Es lo que necesita una sección que se deja de ofrecer sin romper lo enlazado: sus
     * piezas siguen abriéndose y su página responde, pero el lector no llega por la
     * navegación. Retirarla a `draft` no sirve para eso —una sección en preparación no
     * admite piezas publicadas— y `noindex` tampoco: eso es no anunciarla, no esconderla.
     */
    case Hidden = 'hidden';

    /**
     * Si el lector puede abrirla.
     *
     * Dos estados y no uno: `hidden` existe para el lector que llega por una URL. Lo que la
     * separa de `live` es el menú, no la existencia — esa pregunta es `inMenu()`.
     */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /** Si sale en la navegación del periódico, que es lo único que `live` tiene de más. */
    public function inMenu(): bool
    {
        return $this === self::Live;
    }

    /**
     * Cuánto se ve, para poder comparar una sección con su madre.
     *
     * Los tres estados están ordenados —`draft` < `hidden` < `live`— y esa escala es lo que
     * permite escribir la regla del árbol en una línea: **una sección no puede verse más que su
     * madre**. Una hija `live` bajo una madre `hidden` no está en el menú de nadie: el menú
     * tiene dos niveles y el suyo cuelga de un desplegable que no se pinta.
     */
    public function visibility(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Hidden => 1,
            self::Live => 2,
        };
    }
}
