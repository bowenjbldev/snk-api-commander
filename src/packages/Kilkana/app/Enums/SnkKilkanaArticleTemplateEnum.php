<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Las plantillas de pieza: cómo se maqueta la página de un artículo.
 *
 * El catálogo es de Kilkana y el mismo para todos los periódicos; qué hace cada una —dónde va la
 * foto, la voz del titular, la medida— lo dibuja el frontend, que tiene la lista gemela en
 * `src/lib/data/article.ts`. Aquí solo están los ids, que es lo que una sección y una pieza
 * guardan: una sección elige la de sus piezas, y una pieza puede fijar la suya.
 *
 * Duplicada a la fuerza y con una prueba a cada lado, como el vocabulario de bloques: PHP no
 * puede leer un tipo de TypeScript. Añadir una plantilla es añadirla en los dos sitios a la vez.
 */
enum SnkKilkanaArticleTemplateEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    case News = 'news';
    case Feature = 'feature';
    case Opinion = 'opinion';
    case Interview = 'interview';
    case PhotoStory = 'photo-story';
    case Analysis = 'analysis';
    case Review = 'review';
    case Guide = 'guide';
    case MatchReport = 'match-report';
    case Preview = 'preview';
}
