<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Si una seccion existe para el lector o solo para la redaccion.
 *
 * Faltaba el estado intermedio y por eso abrir una mesa nueva no se podia representar: o la
 * seccion existia y salia ya en la navegacion, o no existia. Una redaccion no trabaja asi
 * —se crea la mesa, se le asignan piezas, y se lanza cuando hay con que abrirla—.
 *
 * Ojo con no confundirlo con una seccion viva y VACIA, que es otro estado y tambien es
 * legitimo: `motor` nace sin articulos a proposito en el juego de datos.
 *
 * Tampoco es `noindex`, que es la otra mitad: `noindex` dice «se ve pero no la anuncies a los
 * buscadores»; esto dice «todavia no se ve».
 *
 * Orden de ciclo de vida: se entra por `draft` y se sale lanzando. `hidden` no es un paso de
 * ese camino sino un destino: una seccion que se retira del menu sin retirar sus URLs.
 */
enum SnkKilkanaCategoryStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** En preparacion. No sale al publico, y no admite piezas publicadas. */
    case Draft = 'draft';

    /** Lanzada: navegable, con pagina propia y en el catalogo publico. */
    case Live = 'live';

    /**
     * Alcanzable por su URL y fuera del menu.
     *
     * Es lo que necesita una seccion que se deja de ofrecer sin romper lo enlazado: sus
     * piezas siguen abriendose y su pagina responde, pero el lector ya no llega por la
     * navegacion. Retirarla a `draft` no sirve para eso —una seccion en preparacion no
     * admite piezas publicadas— y `noindex` tampoco: eso es no anunciarla, no esconderla.
     */
    case Hidden = 'hidden';

    /**
     * Si el lector puede abrirla.
     *
     * Dos estados y no uno: `hidden` existe para el lector que llega por una URL. Lo que la
     * separa de `live` es el menu, no la existencia — esa pregunta es `inMenu()`.
     */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /** Si sale en la navegacion del periodico, que es lo unico que `live` tiene de mas. */
    public function inMenu(): bool
    {
        return $this === self::Live;
    }

    /**
     * Cuanto se ve, para poder comparar una seccion con su madre.
     *
     * Los tres estados estan ordenados —`draft` < `hidden` < `live`— y esa escala es lo que
     * permite escribir la regla del arbol en una linea: **una seccion no puede verse mas que su
     * madre**. Una hija `live` bajo una madre `hidden` no esta en el menu de nadie: el menu
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
