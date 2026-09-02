<?php

namespace Packages\Kilkana\Database\Seeders;

/**
 * Lo que dicen los lectores de la demo, por familia de seccion y por tono.
 *
 * Solo texto: quien lo reparte es `seedComments()`. Un comentario de deportes habla del
 * partido y uno de economia de los precios, porque en un periodico de verdad la gente comenta lo
 * que ha leido. Los generales valen para cualquier pieza —agradecen, discrepan, corrigen,
 * preguntan— y son los que hacen que dos hilos de la misma seccion no suenen iguales.
 */
final class SnkKilkanaDemoVoices
{
    /** @var array<string, string[]> */
    public const BY_FAMILY = [
        'local' => [
            'Vivo en esa calle y lo de los nueve semanas de obra ya lo dijeron la última vez. Fueron catorce.',
            'Lo del paso protegido para el colegio debería estar antes de que empiece la obra, no después.',
            '¿Y los que tenemos vado? Nadie ha explicado por dónde entramos a partir del lunes.',
            'Por fin se toca ese tramo. Llevaba desde que era niño con los mismos adoquines levantados.',
            'Las terrazas en la plaza me parece bien, pero que no se coman el paso de los carritos.',
            'Fui a la reunión del centro cívico y la mitad de las preguntas se quedaron sin respuesta.',
            'El mercado en el polideportivo queda lejísimos para la gente mayor de la parte alta.',
            'Ojalá acabe antes de fiestas como dicen, pero yo no apostaría.',
        ],
        'politica' => [
            'Se aprueba por un voto y ya lo venden como consenso. Un poco de seriedad.',
            'Cuatro meses sin comisión de cuentas y nadie dimite. Así nos va.',
            'Que se auditen las facturas me parece bien venga de quien venga.',
            'El pleno empezó con una hora de retraso y lo cuentan como si fuera normal. No lo es.',
            'Votaron en contra de lo mismo que proponían hace dos años. Tengo el programa guardado.',
            'Lo del audio de las comisiones lo llevo pidiendo yo solo desde hace tres mandatos.',
            'Ni con el gobierno ni con la oposición: con el pueblo, que paga la tasa devuelta.',
            'Gracias por publicar el informe de intervención entero. Es lo que hacía falta leer.',
        ],
        'economia' => [
            'Mi padre lleva cuarenta años en la cooperativa y este es el peor precio en origen que recuerda.',
            'Las ayudas con la condición de mantener plantilla dos años son para las grandes. Las pequeñas ni lo intentan.',
            'Se cierra la oficina y nos mandan al cajero. El cajero no te renueva una hipoteca.',
            'El polígono lleno está muy bien, pero los camiones siguen pasando por el centro.',
            'Lo del relevo en el comercio es verdad. Pregunten en la calle mayor cuántos tienen menos de cincuenta.',
            'Buen dato del paro, pero de campaña. En noviembre volvemos a hablar.',
            'El alquiler de los locales ha subido porque los dueños los prefieren vacíos. Cuéntenlo también.',
            'A ver si el programa de traspasos sirve para algo más que para hacerse la foto.',
        ],
        'deportes' => [
            'Estuve en el campo y con diez se mereció mucho más. La grada lo sabe.',
            'El portero es de lo mejor que ha salido de la cantera en años. Que no se lo lleven por cuatro duros.',
            'Nueve temporadas esperando. Yo entre ellos, con mi hijo, que ya tiene la edad que tenía yo entonces.',
            'Lo de suspender por tormenta a falta de veinte minutos es la primera vez que lo veo en este campo.',
            'El pabellón nuevo está muy bien, pero el aparcamiento de siempre sigue sin luz.',
            'Cuatrocientos inscritos en la escuela y un solo campo de entrenamiento. Hagan cuentas.',
            'Enhorabuena a las chicas. Segundo año seguido en la fase de ascenso y casi nadie lo cuenta.',
            'Trece temporadas de la capitana. Ojalá el club le dé la despedida que merece.',
        ],
        'cultura' => [
            'Fui el sábado y el retablo impresiona. Catorce meses bien empleados.',
            'La biblioteca abriendo sábados por la tarde ha cambiado el barrio, y no exagero.',
            'Doce compañías y la mitad de aquí. Eso es lo que hace que el festival dure veinte años.',
            'Las actas del XIX en internet: mi abuelo habría dado cualquier cosa por esto.',
            'Colgaron el cartel de completo en dos horas. Hacen falta más funciones, no más sillas.',
            'El mural del instituto es lo mejor que ha pasado en esa pared en treinta años.',
            'Ojalá la excavación siga el verano que viene. Lo que hay debajo de ese solar da para un museo.',
            'Estuve en el concierto del centenario y se me saltaron las lágrimas con la pieza final.',
        ],
        'sucesos' => [
            'Pasé por allí a las ocho y todavía había humo. Menos mal que no pasó a la nave de al lado.',
            'La curva de la salida es un peligro desde siempre. Esta vez sin heridos, la siguiente no sé.',
            'Lo del radar en la travesía se aprobó en el pleno de enero. ¿A qué esperan?',
            'Gracias a los voluntarios de protección civil. Sin ellos el crío pasa la noche en el monte.',
            'Treinta y ocho litros en una hora y el paso de la estación otra vez cerrado. Como cada otoño.',
            'Espero que la empresa no reabra la planta hasta que se sepa qué pasó. Ahí trabaja mi cuñado.',
            'Mucha prudencia con lo de los detenidos hasta que hable el juzgado, por favor.',
            'El detector de humos cuesta veinte euros. Que este artículo sirva para que alguien se lo ponga.',
        ],
        'opinion' => [
            'No estoy de acuerdo con la conclusión, pero se agradece que alguien lo escriba con nombre.',
            'Lo de que las plazas son aparcamientos con bancos alrededor es tan cierto que duele.',
            'Fácil criticar desde la columna. Que se presente a las elecciones.',
            'Lo urgente comiéndose a lo importante. Veinte años. Exacto.',
            'Por una vez un elogio a la oposición, y con razón. Que no se acostumbren.',
            'La participación que no cambia nada tiene otro nombre, sí: teatro.',
            'Cuando el periódico publica algo incómodo se nota en los comentarios. Sigan así.',
            'Buen texto, pero un poco de esperanza no vendría mal de vez en cuando.',
        ],
        'ambiente' => [
            'El embalse al treinta y ocho por ciento y los jardines públicos regados a mediodía. Explíquenlo.',
            'Doscientas hectáreas de placas en el secano de mis abuelos. Firmaré la alegación.',
            'Retirar los azudes sin uso es de sentido común. Llevaba años pidiéndolo el club de pesca.',
            'Ciento cuatro parejas en el humedal. Hace diez años no llegaban a cuarenta. Algo se hace bien.',
            'El aceite doméstico al punto de recogida: lo hago desde enero y no cuesta nada.',
            'Lo de la depuradora vertiendo desde marzo lo sabía todo el mundo que baja al río.',
            'Sombra en los patios de los colegios antes que en las rotondas. Eso es lo que hay que plantar.',
            'La ruta verde es una maravilla, pero el mirador no tiene ni una fuente.',
        ],
    ];

    /** @var string[] */
    public const GENERAL = [
        'Gran cobertura, gracias por el contexto histórico.',
        'Faltan las cifras de la fuente original, ¿las tenéis?',
        'Lo he compartido con mi equipo, muy útil.',
        'No estoy de acuerdo con la conclusión, pero se agradece el dato.',
        'Seguid así, es el tipo de periodismo que hace falta.',
        'Yo vivo en el barrio y la situación es bastante peor de lo que se cuenta aquí.',
        'El titular promete más de lo que luego desarrolla el texto.',
        '¿Vais a hacer seguimiento del tema dentro de unos meses?',
        'Enhorabuena por el trabajo de campo, se nota que hay calle detrás.',
        'Falta la versión de la otra parte, así queda cojo.',
        'La foto de portada no corresponde con lo que se describe en el tercer párrafo.',
        'Llevo años suscrito y este es de los mejores reportajes que os he leído.',
        'Una errata en el segundo párrafo: pone «ayutamiento».',
        'Me habría gustado un mapa. Los que no somos de aquí nos perdemos.',
        'Lo leí en papel el domingo y en la web está más completo. Bien.',
        'Comparto con matices. El dato de la última línea no es de este año.',
    ];

    /** @var string[] */
    public const REPLIES = [
        'Justo lo que iba a comentar. Añado que la última parte contradice lo que publicasteis en mayo.',
        'Yo lo veo al revés: si no se hubiera hecho ahora, el año que viene costaba el doble.',
        'Tienes razón en lo de las cifras. Las del ayuntamiento y las de la cooperativa no cuadran.',
        'Pues yo pasé ayer por allí y ya han empezado. Otra cosa es cuándo acaben.',
        'Gracias por el apunte, no conocía ese informe. ¿Está publicado en algún sitio?',
        'No estoy de acuerdo, pero al menos aquí se puede discutir sin gritos.',
        'Eso mismo dije yo en la asamblea y me miraron raro.',
        'Cuidado, que eso no es lo que dice el artículo. Léelo otra vez.',
        'Coincido. Y añado que en el pueblo de al lado lo resolvieron en dos meses.',
        'A mí me pasó lo mismo el año pasado y nadie me hizo caso.',
        'Buena pregunta. Yo también quiero saber quién paga eso.',
        'Un poco de calma. Todavía no se sabe nada seguro.',
        'Ojalá tengas razón, pero mi experiencia dice lo contrario.',
        'Lo de la fuente original está en la web de la consejería, lo acabo de mirar.',
        'Es que no es solo esta calle, es todo el barrio.',
    ];

    /** @var string[] */
    public const SPAM = [
        'GANA DINERO DESDE CASA >>> clic aquí, plazas limitadas!!!',
        'Compra seguidores baratos en www.ejemplo-no-real.test/promo',
        'PRÉSTAMOS SIN AVAL en 24h, escribe al 600 000 000',
        'Descuento del 70% en cursos online, solo hoy: ejemplo-no-real.test/cursos',
        'He ganado 3.000 € al mes con este método, pregúntame cómo',
        'Vendo piso en la zona, contactar por privado, precio negociable!!!',
    ];
}
