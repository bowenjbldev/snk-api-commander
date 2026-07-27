<?php

namespace Packages\Snk\Kilkana\Enums;

use Packages\Snk\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Los documentos componibles del periodico, y la UNICA definicion de cuales son.
 *
 * No es un estado: es una lista cerrada de DOCUMENTOS. Le aplica la misma regla igualmente
 * —una lista cerrada se declara como tal— porque el problema era el mismo: los dos valores
 * estaban escritos a mano en cinco `whereIn` de las rutas y la columna era un `varchar(16)`
 * que aceptaba cualquier cosa.
 *
 * Con una puerta lateral, ademas: el servicio hace `firstOrCreate`, asi que cualquier llamada
 * con una cadena inventada CREABA la fila. Lo unico que lo impedia era el filtro de las rutas,
 * por el que no pasan ni el seeder ni la consola.
 *
 * Hoy son dos y llevan tiempo siendolo: la portada y la seccion. Anadir un tercero es anadir
 * un caso aqui, y la migracion y las rutas se enteran solas.
 */
enum SnkKilkanaLayoutEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** La portada del periodico. */
    case Front = 'front';

    /** La composicion que comparten TODAS las secciones: lo que cambia es el pool de piezas. */
    case Section = 'section';
}
