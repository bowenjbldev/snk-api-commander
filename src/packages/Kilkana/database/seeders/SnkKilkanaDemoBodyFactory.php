<?php

namespace Packages\Kilkana\Database\Seeders;

/**
 * El cuerpo de las piezas de demo, compuesto a partir de lo que el snapshot ya sabe de cada una.
 *
 * El snapshot trae titular, entradilla, seccion, firma, foto y etiquetas, pero NO cuerpo, y sin
 * cuerpo media demo no significa nada: el articulo publico sale en blanco, el editor abre una
 * pieza vacia y `content_tier` no corta por ningun sitio porque no hay muro que colocar. Escribir
 * 522 cuerpos a mano tampoco es la respuesta —envejecen sueltos del titular que acompanan—, asi
 * que se componen aqui, con la entradilla real como arranque y prosa de relleno por detras.
 *
 * **Determinista y sin azar**: todo lo que se elige sale de `crc32($slug)`, asi que dos siembras
 * dan el mismo cuerpo y un diff de la base no se llena de ruido. Es la misma regla que ya siguen
 * `seedViews` y `seedComments`.
 *
 * **La prosa es de relleno y se nota a proposito.** Va por familia de seccion —ocho, no dieciseis:
 * `futbol` y `polideportivo` se leen igual de creibles con el mismo material— y solo tiene que
 * sostener la pagina: parrafos de largo real, un intertitulo, una cita atribuida y un recuadro de
 * cifras. Lo especifico de cada pieza sale de sus propios datos.
 *
 * El bloque es `{id, type, config}`, que es lo que valida el controlador y lo que lee
 * `publicBodyBlocks()`. Dentro de `config` van los campos del bloque del editor tal cual
 * —`text`, `source`, `items`, `caption`—, con una sola diferencia: la foto viaja como `media_id`,
 * la PK de verdad de `kilkana_media`, y no como el id de la mediateca de la fixture.
 */
final class SnkKilkanaDemoBodyFactory
{
    /**
     * A que familia de prosa pertenece cada seccion.
     *
     * Las dieciseis secciones se reparten en ocho: una subseccion se lee con el material de su
     * mesa, y separar `futbol` de `polideportivo` habria duplicado el texto sin que nadie note la
     * diferencia. Una seccion que no este aqui cae en `local`, que es la mesa de un diario
     * comarcal y el relleno menos llamativo.
     */
    private const FAMILIA = [
        'local' => 'local', 'comarca' => 'local', 'municipios' => 'local', 'barrios' => 'local',
        'politica' => 'politica',
        'economia' => 'economia', 'agricultura' => 'economia', 'empleo' => 'economia',
        'deportes' => 'deportes', 'futbol' => 'deportes', 'polideportivo' => 'deportes',
        'cultura' => 'cultura', 'patrimonio' => 'cultura', 'agenda' => 'cultura',
        'sucesos' => 'sucesos',
        'opinion' => 'opinion',
        'medio-ambiente' => 'ambiente',
    ];

    /**
     * El material de cada familia: prosa, intertitulos y citas con quien las dice.
     *
     * Tres parrafos por familia y dos de cada uno del resto: con dos, la mitad de las piezas de
     * una seccion comparten cita, y eso se ve de un vistazo en el listado. Con tres parrafos y el
     * desfase del hash, dos piezas seguidas casi nunca coinciden en las tres cosas.
     *
     * @var array<string, array{prosa: string[], intertitulos: string[], citas: array{0: string, 1: string}[]}>
     */
    private const MATERIAL = [
        'local' => [
            'prosa' => [
                'La sesión se alargó más de lo previsto y el punto no se votó hasta bien entrada la tarde, con los dos grupos de la oposición pidiendo que el expediente volviera a comisión.',
                'El área responsable calcula que los trabajos afectarán al tráfico durante nueve semanas, con desvíos señalizados en las tres calles perpendiculares y un servicio de refuerzo los sábados por la mañana.',
                'Los vecinos llevaban dos años reclamando una respuesta y en la última asamblea de barrio recogieron cuatrocientas firmas, que registraron en el ayuntamiento en marzo.',
            ],
            'intertitulos' => ['Qué cambia a partir de octubre', 'Los vecinos piden plazos'],
            'citas' => [
                ['No vamos a dejar a nadie sin alternativa a menos de trescientos metros', 'la concejala del área, en el pleno'],
                ['Llevamos dos años oyendo lo mismo y la calle sigue igual', 'la portavoz de la asociación de vecinos'],
            ],
        ],
        'politica' => [
            'prosa' => [
                'La votación salió adelante por un solo voto y el grupo que se abstuvo dejó claro que su apoyo no se repetirá en el pleno de presupuestos.',
                'El acuerdo llega después de tres reuniones a puerta cerrada en las que, según las dos partes, se descartó una comisión de investigación a cambio de auditar el contrato.',
                'La oposición anunció que llevará el expediente al órgano de cuentas y pidió que comparezca el responsable técnico que firmó el informe.',
            ],
            'intertitulos' => ['Los números del acuerdo', 'Qué dice la oposición'],
            'citas' => [
                ['Este acuerdo no compra nada, ordena lo que llevaba diez años sin ordenarse', 'el portavoz del equipo de gobierno'],
                ['Que se vote hoy no significa que esté cerrado', 'la portavoz de la oposición'],
            ],
        ],
        'economia' => [
            'prosa' => [
                'Las cifras que maneja la patronal comarcal apuntan a que el efecto se notará en el primer trimestre, con una caída de pedidos que ya arrastran los proveedores desde el verano.',
                'El sector emplea a algo más de mil doscientas personas en la comarca, la mayoría con contratos de campaña, y la temporada corta arranca este año dos semanas antes.',
                'La línea de ayudas se abre en septiembre y exige mantener la plantilla dos años, una condición que las empresas más pequeñas ya han advertido que no podrán cumplir.',
            ],
            'intertitulos' => ['El impacto en la comarca', 'Las cuentas del sector'],
            'citas' => [
                ['Con estos precios no se cubre ni el coste de recogida', 'un productor de la cooperativa'],
                ['El problema no es la campaña de este año, es que no hay relevo', 'el secretario de la patronal comarcal'],
            ],
        ],
        'deportes' => [
            'prosa' => [
                'El equipo llegó al último cuarto de hora con un jugador menos y aun así tuvo dos ocasiones claras, las dos en la misma jugada de estrategia que llevaba semanas ensayando.',
                'La plantilla arrastra tres bajas por lesión y el cuerpo técnico ha tirado del filial en las últimas cuatro jornadas, con dos debutantes de menos de veinte años.',
                'La afición llevaba nueve temporadas esperando y llenó el campo por primera vez desde el ascenso anterior, con mil doscientos desplazados en la grada visitante.',
            ],
            'intertitulos' => ['Cómo se decidió el partido', 'Lo que viene ahora'],
            'citas' => [
                ['Hemos competido con lo que teníamos y eso vale más que el resultado', 'el entrenador, en la sala de prensa'],
                ['Esto es de la gente, que lleva nueve años viniendo cuando no había nada', 'el capitán, tras el partido'],
            ],
        ],
        'cultura' => [
            'prosa' => [
                'La pieza estaba catalogada desde los años setenta pero nunca se había expuesto: llevaba cuatro décadas en el depósito, envuelta y sin ficha, hasta que la revisión del inventario la sacó.',
                'El programa reúne a doce compañías, la mitad de la comarca, y mantiene la entrada libre en las funciones de tarde gracias al convenio con la diputación.',
                'La restauración ha durado catorce meses y ha obligado a desmontar el retablo pieza a pieza, con un andamio interior que ha tenido la nave cerrada al culto desde enero.',
            ],
            'intertitulos' => ['Qué se puede ver', 'El proceso de restauración'],
            'citas' => [
                ['Lo teníamos delante y no lo habíamos mirado con calma en cuarenta años', 'la responsable de colección'],
                ['Sin el convenio esto no sale adelante ni un año más', 'el director del festival'],
            ],
        ],
        'sucesos' => [
            'prosa' => [
                'El aviso entró en el 112 a las siete y cuarto de la mañana y hasta el lugar se desplazaron dos dotaciones de bomberos, una ambulancia y una patrulla, que cortó el tráfico en los dos sentidos.',
                'La investigación sigue abierta y por el momento no se descarta ninguna hipótesis, según fuentes del instituto armado, que pide prudencia hasta que se complete el atestado.',
                'No hubo que lamentar heridos de gravedad: dos personas fueron atendidas en el lugar por inhalación de humo y una tercera quedó en observación hasta media mañana.',
            ],
            'intertitulos' => ['Cómo ocurrió', 'La investigación'],
            'citas' => [
                ['Se ha actuado rápido y eso ha evitado que pasara a la nave contigua', 'el jefe de la dotación'],
                ['Hasta que no esté el atestado no vamos a aventurar nada', 'fuentes de la investigación'],
            ],
        ],
        'opinion' => [
            'prosa' => [
                'Conviene recordar que esto no empezó esta legislatura. Empezó cuando se decidió que era más barato aplazarlo, y aplazarlo salió, como siempre, mucho más caro.',
                'Hay una diferencia entre no tener recursos y no tener criterio, y llevamos demasiados años usando lo primero para no hablar de lo segundo.',
                'Se puede discutir el calendario, el coste y hasta la oportunidad. Lo que ya no se puede es seguir discutiendo si hacía falta.',
            ],
            'intertitulos' => ['Una cuestión de prioridades', 'Lo que no se dice'],
            'citas' => [
                ['Lo urgente lleva veinte años comiéndose a lo importante', 'de la tribuna de esta semana'],
                ['No es un problema de dinero, es un problema de orden', 'de la tribuna de esta semana'],
            ],
        ],
        'ambiente' => [
            'prosa' => [
                'Las estaciones de medida de la comarca registran el tercer verano consecutivo por encima de la media de la serie histórica, que arranca en 1961.',
                'El plan prevé renaturalizar dos kilómetros de cauce y retirar los tres azudes que quedan sin uso, una obra que la confederación tiene presupuestada para el próximo ejercicio.',
                'La población censada se ha estabilizado después de una década a la baja, aunque los técnicos advierten de que dos temporadas buenas no revierten una tendencia.',
            ],
            'intertitulos' => ['Lo que dicen los datos', 'El plan, paso a paso'],
            'citas' => [
                ['Dos años buenos no son una recuperación, son dos años buenos', 'el técnico responsable del seguimiento'],
                ['Si no se actúa sobre el cauce, lo demás es cosmética', 'la portavoz del grupo ecologista'],
            ],
        ],
    ];

    /**
     * El cuerpo de una pieza del snapshot.
     *
     * @param  array<string, mixed>  $post  la fila del snapshot
     * @param  int|null  $coverId  la PK de la portada ya sembrada, si la pieza trae foto
     * @return array<int, array{id: string, type: string, config: array<string, mixed>}>
     */
    public static function blocks(array $post, ?int $coverId): array
    {
        $familia = self::FAMILIA[$post['cat']] ?? 'local';
        $material = self::MATERIAL[$familia];
        $hash = crc32((string) $post['slug']);
        $pick = fn (array $pool, int $desfase) => $pool[intdiv($hash, $desfase) % count($pool)];

        $etiqueta = $post['tags'][0] ?? null;
        [$cita, $quien] = $pick($material['citas'], 1);

        $bloques = [
            // La entradilla ES el primer parrafo. En un periodico lo es: el resumen que se lee en
            // la portada y la entrada de la pieza son el mismo texto, y separarlos aqui habria
            // obligado a escribir 522 arranques que dijeran lo mismo con otras palabras.
            self::texto('paragraph', $post['excerpt']),
            self::texto('paragraph', $pick($material['prosa'], 7)),
            self::texto('heading', $pick($material['intertitulos'], 3)),
        ];

        if ($coverId !== null) {
            $bloques[] = ['type' => 'image', 'config' => [
                'media_id' => $coverId,
                // El pie repite el titular a proposito: es lo que hace una redaccion cuando la
                // foto es la de portada y no tiene pie propio, y deja ver que el pie existe.
                'caption' => $post['title'],
            ]];
        }

        $bloques[] = ['type' => 'quote', 'config' => ['text' => $cita, 'source' => $quien]];
        $bloques[] = self::texto('paragraph', $pick($material['prosa'], 31));

        // Una de cada tres lleva recuadro de cifras, y solo si tiene de que hacerlo: las etiquetas
        // de la pieza mas su audiencia. Ponerlo en todas lo convertiria en decoracion fija.
        if ($hash % 3 === 0 && count($post['tags'] ?? []) >= 2) {
            $bloques[] = self::texto('factbox', implode(' · ', array_merge(
                array_map(ucfirst(...), array_slice($post['tags'], 0, 3)),
                [number_format((int) ($post['reads'] ?? 0), 0, ',', '.').' lecturas'],
            )));
        }

        if ($etiqueta !== null) {
            $bloques[] = self::texto('paragraph', 'El seguimiento de '.$etiqueta.' continúa en las próximas semanas, cuando se conozcan los informes que las dos partes se comprometieron a presentar.');
        }

        // El muro va DENTRO del cuerpo y no al final, que es lo unico que lo hace significar algo:
        // `publicBodyBlocks()` corta ahi, asi que su posicion es literalmente cuanto se lee gratis.
        // Una pieza `locked` ensena el arranque; una `metered`, casi toda menos el remate.
        $corte = match ($post['content_tier']) {
            'locked' => 2,
            'metered' => count($bloques) - 1,
            default => null,
        };
        if ($corte !== null) {
            array_splice($bloques, $corte, 0, [['type' => 'paywall', 'config' => []]]);
        }

        // Los relacionados rematan la pieza, y solo la publicada: en un borrador no hay archivo
        // al que enlazar todavia.
        if ($post['status'] === 'published') {
            $bloques[] = ['type' => 'divider', 'config' => []];
            $bloques[] = ['type' => 'related', 'config' => []];
        }

        // Los ids se numeran al final y no al construir: insertar el muro por el medio habria
        // dejado la numeracion saltada, y el editor arranca su contador detras del mayor id.
        return array_values(array_map(
            fn (array $bloque, int $n) => ['id' => 'b'.($n + 1)] + $bloque,
            $bloques,
            array_keys($bloques),
        ));
    }

    /** @return array{type: string, config: array{text: string}} */
    private static function texto(string $tipo, string $texto): array
    {
        return ['type' => $tipo, 'config' => ['text' => $texto]];
    }
}
