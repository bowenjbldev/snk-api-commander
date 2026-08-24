<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * De que esta hecho el cuerpo de una pieza.
 *
 * `body_blocks` es el UNICO cuerpo, y esta es su lista cerrada. Vive en un enum y no en una
 * cadena `in:` dentro del controlador porque la escriben dos sitios —las reglas de alta y las
 * de actualizacion— y la lee un tercero, `publicBodyBlocks()`, que decide por donde corta el
 * muro. Tres copias de la misma lista es como una se queda atras.
 *
 * **El vocabulario lo manda el editor, no la base.** Son los diez tipos que compone el CMS, y
 * la razon de que sean estos y no otros es que el `type` es lo que el backend razona y lo que
 * un renderizador publico va a conmutar: un `type` que miente para tres de cada diez bloques
 * es una averia que se paga entera meses despues. Aqui hubo siete, con `text` donde el editor
 * dice `paragraph` y con `gallery` y `video` que el editor no compone; la migracion
 * `rename_text_body_blocks_to_paragraph` reconcilio los datos.
 */
enum SnkKilkanaBlockTypeEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Cuerpo corriente. Su HTML se sanea al escribir, no al leer. */
    case Paragraph = 'paragraph';

    /** Un intertitulo dentro del cuerpo. */
    case Heading = 'heading';

    case Image = 'image';

    case Quote = 'quote';

    case List = 'list';

    /** Incrustado de un tercero: vídeo, red social, mapa. */
    case Embed = 'embed';

    case Divider = 'divider';

    /**
     * Por donde corta el muro.
     *
     * Cuantas veces puede aparecer cada tipo en una pieza **no se decide aqui**: es una regla
     * de composicion del editor —`blockKinds.once` en `src/lib/data/article.ts`— y este
     * backend no la comprueba. Duplicarla sin usarla seria una segunda fuente que solo puede
     * quedarse vieja; ya lo hizo una vez, marcando este tipo como el unico irrepetible cuando
     * el editor tambien lo dice de `related`.
     */
    case Paywall = 'paywall';

    /** El recuadro de datos que acompaña a la pieza. */
    case Factbox = 'factbox';

    /** Enlaces a piezas relacionadas, colgados del cuerpo. */
    case Related = 'related';
}
