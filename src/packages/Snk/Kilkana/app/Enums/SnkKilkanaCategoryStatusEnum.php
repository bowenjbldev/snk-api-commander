<?php

namespace Packages\Snk\Kilkana\Enums;

use Packages\Snk\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

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
 * Orden de ciclo de vida: se entra por `draft` y se sale lanzando.
 */
enum SnkKilkanaCategoryStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** En preparacion. No sale al publico, y no admite piezas publicadas. */
    case Draft = 'draft';

    /** Lanzada: navegable, con pagina propia y en el catalogo publico. */
    case Live = 'live';

    /** Si el lector la ve. */
    public function isPublic(): bool
    {
        return $this === self::Live;
    }
}
