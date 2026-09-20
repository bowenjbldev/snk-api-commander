<?php

namespace Packages\Kilkana\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Saneado en el servidor del HTML rico del editor antes de persistirlo. Solo admite estas
 * etiquetas, `text-align` en <p> y enlaces `http`, `https` o `mailto`. Es la frontera de
 * confianza: lo guardado ya es seguro para su renderizado posterior sin depender del cliente.
 */
class SnkKilkanaRichText
{
    /** Etiquetas permitidas, `text-align` solo en <p> y el destino de un enlace. */
    private const ALLOWED = 'p[style],br,strong,b,em,i,s,code,a[href]';

    private static ?HTMLPurifier $purifier = null;

    /** Sanea un fragmento HTML; null y '' pasan tal cual. */
    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    /**
     * Recorre body_blocks y sanea el `config.html` de cualquier bloque que lo traiga.
     *
     * **Por tener HTML, no por ser de un tipo.** Mirar solo el bloque de texto ataría la
     * seguridad al vocabulario: el día que el editor compone una cita, un recuadro de datos o
     * un intertítulo con marcado, ese HTML entraría sin pasar por aquí. Saneando por presencia
     * del campo, un tipo nuevo nace cubierto.
     */
    public static function cleanBlocks(?array $blocks): ?array
    {
        if ($blocks === null) {
            return null;
        }

        return array_map(function (array $block): array {
            if (isset($block['config']['html']) && is_string($block['config']['html'])) {
                $block['config']['html'] = self::clean($block['config']['html']);
            }

            return $block;
        }, $blocks);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', self::ALLOWED);
            $config->set('CSS.AllowedProperties', 'text-align');
            // Un enlace del cuerpo lleva a una web o a un correo; `javascript:` y `data:` se caen.
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            // Sin caché en disco: la definición es fija y trivial, no necesita serializarse.
            $config->set('Cache.DefinitionImpl', null);
            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
