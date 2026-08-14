<?php

namespace Packages\Kilkana\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Saneado server-side del HTML rico del editor (Tiptap) antes de persistirlo. Solo admite estos
 * tags y, en <p>, `text-align`. Es la frontera de confianza: lo guardado ya es seguro para su
 * renderizado posterior sin depender del cliente.
 */
class SnkKilkanaRichText
{
    /** Tags permitidos y `text-align` solo en <p>. */
    private const ALLOWED = 'p[style],br,strong,b,em,i,s,code';

    private static ?HTMLPurifier $purifier = null;

    /** Sanea un fragmento HTML; null y '' pasan tal cual. */
    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    /** Recorre body_blocks y sanea `config.html` de los bloques de texto. */
    public static function cleanBlocks(?array $blocks): ?array
    {
        if ($blocks === null) {
            return null;
        }

        return array_map(function (array $block): array {
            if (($block['type'] ?? null) === 'text' && isset($block['config']['html'])) {
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
            // Sin caché en disco: la definición es fija y trivial, no necesita serializarse.
            $config->set('Cache.DefinitionImpl', null);
            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
