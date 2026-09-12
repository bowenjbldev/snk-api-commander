<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Los documentos componibles del periódico, y la ÚNICA definición de cuáles son.
 *
 * No es un estado: es una lista cerrada de DOCUMENTOS, y le aplica la misma regla —una lista
 * cerrada se declara como tal—. De aquí la leen los `whereIn` de las rutas y la columna, que
 * de otro modo aceptaría cualquier cosa.
 *
 * Y hay una puerta lateral: el servicio hace `firstOrCreate`, así que una llamada con una
 * cadena inventada CREARÍA la fila, y el filtro de las rutas no lo pasan ni el seeder ni la
 * consola.
 *
 * Son dos: la portada y la sección. Añadir un tercero es añadir un caso aquí, y la migración y
 * las rutas se enteran solas.
 */
enum SnkKilkanaLayoutEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** La portada del periódico. */
    case Front = 'front';

    /** La composición que comparten TODAS las secciones: lo que cambia es el pool de piezas. */
    case Section = 'section';
}
