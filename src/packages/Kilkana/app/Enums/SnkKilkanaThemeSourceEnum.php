<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * De dónde sale una variante: la trajo el catálogo o la hizo esta redacción.
 *
 * Es la distinción que sostiene el congelado. Una fila `catalog` la escribe y la reescribe
 * `kilkana:sync-themes`; una fila `tenant` no la toca nadie más que el periódico que la
 * adoptó. Vestir un tema copia la primera en la segunda, y por eso publicar una versión nueva
 * del tema no le cambia la portada a nadie.
 *
 * Sustituye a `is_builtin`, que solo sabía decir «no se borra». Aquí lo que importa es quién
 * manda sobre la fila.
 */
enum SnkKilkanaThemeSourceEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** La trajo el fichero maestro. Se sobrescribe al sincronizar y no se viste directamente. */
    case Catalog = 'catalog';

    /** La adoptó o la retocó esta redacción. Intocable para el sincronizador. */
    case Tenant = 'tenant';
}
