<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * De donde salio el correo de un suscriptor, y la UNICA definicion de los origenes.
 *
 * No es burocracia: el RGPD pone la carga de la prueba en el responsable —que aqui es el
 * PERIODICO, no nosotros—, asi que una lista sin constancia de como se recogio no se puede
 * defender ante una reclamacion aunque el alta fuera legitima.
 *
 * Y sirve para separar el riesgo: la lista importada es la que lo trae, y sin origen todas
 * las filas parecen iguales, asi que no se puede ni acreditar ni purgar por separado.
 *
 * Lo pone SIEMPRE el servidor. Un origen que elige quien llama no prueba nada.
 */
enum SnkKilkanaConsentSourceEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Se apunto el solo, desde el formulario publico del periodico. */
    case PublicForm = 'public_form';

    /** Venia en una lista que trajo el periodico. El consentimiento se dio ANTES y fuera. */
    case Import = 'import';

    /** Lo dio de alta la redaccion desde el panel. */
    case Admin = 'admin';

    /** Si el sistema tiene constancia propia del acto, en vez de fiarse de lo que le contaron. */
    public function isSelfServed(): bool
    {
        return $this === self::PublicForm;
    }
}
