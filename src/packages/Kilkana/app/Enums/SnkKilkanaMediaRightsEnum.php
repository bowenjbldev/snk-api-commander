<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Con que derecho se publica un fichero de la biblioteca.
 *
 * No es burocracia: publicar una foto sin derecho es lo unico de esta base que puede acabar
 * en una factura. Por eso el estado de arranque es `unknown` y no una suposicion amable —una
 * pieza sin clasificar tiene que DESTACAR en la biblioteca, que es justo lo que hace la
 * pestaña «revisar».
 *
 * Orden de ciclo de vida: se entra por `unknown` y se sale clasificando. Los cuatro
 * restantes van de mas atado a mas libre, que es tambien el orden en que hay que mirarlos
 * cuando algo caduca.
 */
enum SnkKilkanaMediaRightsEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Sin clasificar. El estado de llegada, y el que pide trabajo. */
    case Unknown = 'unknown';

    /** Del periodico: foto propia, encargo pagado, cesion en firme. */
    case Owned = 'owned';

    /** Licencia de un tercero. Es la unica que suele traer fecha de caducidad. */
    case Licensed = 'licensed';

    /** Creative Commons. Se puede usar con atribucion, que vive en `credit`. */
    case CreativeCommons = 'creative_commons';

    /** Dominio publico. Sin ataduras. */
    case PublicDomain = 'public_domain';

    /** Lo que cuenta la pestaña «revisar» de la biblioteca. */
    public function needsReview(): bool
    {
        return $this === self::Unknown;
    }
}
