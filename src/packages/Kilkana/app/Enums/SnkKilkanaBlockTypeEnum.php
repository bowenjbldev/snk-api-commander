<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * De qué está hecho el cuerpo de una pieza.
 *
 * `body_blocks` es el ÚNICO cuerpo, y esta es su lista cerrada. Vive en un enum y no en una
 * cadena `in:` dentro del controlador porque la escriben dos sitios —las reglas de alta y las
 * de actualización— y la lee un tercero, `publicBodyBlocks()`, que decide por dónde corta el
 * muro. Tres copias de la misma lista es como una se queda atrás.
 *
 * **El vocabulario lo manda el editor, no la base.** Son los diez tipos que compone el CMS, y
 * la razón de que sean estos y no otros es que el `type` es lo que el backend razona y lo que
 * un renderizador público va a conmutar: un `type` que miente para tres de cada diez bloques
 * es una avería que se paga entera meses después. La migración
 * `rename_text_body_blocks_to_paragraph` alinea los datos con este vocabulario.
 */
enum SnkKilkanaBlockTypeEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Cuerpo corriente. Su HTML se sanea al escribir, no al leer. */
    case Paragraph = 'paragraph';

    /** Un intertítulo dentro del cuerpo. */
    case Heading = 'heading';

    case Image = 'image';

    case Quote = 'quote';

    case List = 'list';

    /** Incrustado de un tercero: vídeo, red social, mapa. */
    case Embed = 'embed';

    case Divider = 'divider';

    /**
     * Por dónde corta el muro.
     *
     * Cuántas veces puede aparecer cada tipo en una pieza **no se decide aquí**: es una regla
     * de composición del editor —`blockKinds.once` en `src/lib/data/article.ts` de
     * `kilkana.app`— y este backend no la comprueba. Duplicarla sin usarla sería una segunda
     * fuente que solo puede quedarse vieja.
     */
    case Paywall = 'paywall';

    /** El recuadro de datos que acompaña a la pieza. */
    case Factbox = 'factbox';

    /** Enlaces a piezas relacionadas, colgados del cuerpo. */
    case Related = 'related';
}
