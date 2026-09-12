<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * De dónde salió el correo de un suscriptor, y la ÚNICA definición de los orígenes.
 *
 * No es burocracia: el RGPD pone la carga de la prueba en el responsable —que aquí es el
 * PERIÓDICO, no nosotros—, así que una lista sin constancia de cómo se recogió no se puede
 * defender ante una reclamación aunque el alta fuera legítima.
 *
 * Y sirve para separar el riesgo: la lista importada es la que lo trae, y sin origen todas
 * las filas parecen iguales, así que no se puede ni acreditar ni purgar por separado.
 *
 * Lo pone SIEMPRE el servidor. Un origen que elige quien llama no prueba nada.
 */
enum SnkKilkanaConsentSourceEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Se apuntó él solo, desde el formulario público del periódico. */
    case PublicForm = 'public_form';

    /** Venía en una lista que trajo el periódico. El consentimiento se dio ANTES y fuera. */
    case Import = 'import';

    /** Lo dio de alta la redacción mediante el flujo administrativo. */
    case Admin = 'admin';

    /** Si el sistema tiene constancia propia del acto, en vez de fiarse de lo que le contaron. */
    public function isSelfServed(): bool
    {
        return $this === self::PublicForm;
    }
}
