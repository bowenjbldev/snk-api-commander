<?php

return [
    // Solo con la demo activa se siembra el periódico de ejemplo.
    'seed_demo' => (bool) env('KILKANA_SEED_DEMO', false),

    /*
     * La fábrica de imágenes: lo que se produce de cada foto y hasta dónde.
     *
     * `max_megapixels` es control de seguridad, no afinado: GD reserva cuatro bytes por píxel de
     * la imagen de ENTRADA, así que un JPEG de dos megas puede pedir cien megapíxeles de memoria.
     * Se lee de la cabecera antes de decodificar; por encima, 422. Con 20 y `memory_limit` 512M
     * la subida cabe; la generación parte del máster y no del original, y cabe de sobra.
     */
    // Se lee como `kilkana.kilkana.media.*`: el proveedor fusiona este fichero bajo `kilkana`.
    'media' => [
        'max_megapixels' => (int) env('KILKANA_MEDIA_MAX_MEGAPIXELS', 20),
        // El lado mayor del máster, del que salen todas las derivadas. Más que cualquier hueco
        // de un tema (1920 a lo sumo) y menos que una cámara.
        'master' => (int) env('KILKANA_MEDIA_MASTER', 2560),
        // El 160 es para las miniaturas del panel —la fila de la tabla pinta 26 px, el
        // selector de cartel 160—, que con 320 de suelo bajaban doce veces más de lo que pintan.
        'widths' => [160, 320, 640, 960, 1280, 1920],
        'quality' => ['avif' => 55, 'webp' => 80, 'jpeg' => 82, 'master' => 90],
    ],
];
