<?php

namespace Packages\Kilkana\Enums\Concerns;

/**
 * Las dos derivaciones que necesita cualquier enum que sea una columna.
 *
 * Un enum de este paquete se usa en tres sitios y siempre igual: la migracion declara la
 * columna con la lista, la API valida contra la lista, y el codigo compara con casos. Los
 * dos primeros son la misma lista escrita dos veces, asi que se derivan aqui de `cases()`
 * y ya no se pueden separar.
 *
 * Lo que NO entra aqui es lo que cada enum SIGNIFICA —`isAccessibleForFree`, `isPublic`,
 * `needsReview`—: eso es un caso por enum, no un patron compartido.
 */
trait SnkKilkanaEnumValuesSupport
{
    /**
     * Los valores en crudo, para que la migracion declare la columna con esta misma lista.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Regla de validacion de la API, derivada. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
