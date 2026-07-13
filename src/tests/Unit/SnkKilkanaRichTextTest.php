<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Packages\Snk\Kilkana\Support\SnkKilkanaRichText;

class SnkKilkanaRichTextTest extends TestCase
{
    public function test_conserva_el_formato_permitido(): void
    {
        // HTMLPurifier normaliza el style con `;` final; el resto se conserva íntegro.
        $html = '<p style="text-align:center">Hola <strong>mundo</strong> <em>y</em> <s>tachado</s> <code>x</code></p>';
        $expected = '<p style="text-align:center;">Hola <strong>mundo</strong> <em>y</em> <s>tachado</s> <code>x</code></p>';
        $this->assertSame($expected, SnkKilkanaRichText::clean($html));
    }

    public function test_elimina_script_y_handlers(): void
    {
        $out = SnkKilkanaRichText::clean('<p onclick="alert(1)">hola</p><script>alert(2)</script><img src=x onerror=alert(3)>');
        $this->assertStringNotContainsStringIgnoringCase('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('hola', $out);
    }

    public function test_bloquea_enlaces_javascript(): void
    {
        // No hay <a> en el allowlist: se elimina el tag, sobrevive el texto.
        $out = SnkKilkanaRichText::clean('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('<a', $out);
    }

    public function test_solo_admite_text_align_en_p(): void
    {
        $out = SnkKilkanaRichText::clean('<p style="text-align:right;color:red;position:fixed">x</p>');
        $this->assertStringContainsString('text-align:right', $out);
        $this->assertStringNotContainsString('color', $out);
        $this->assertStringNotContainsString('position', $out);
    }

    public function test_null_y_vacio_pasan_igual(): void
    {
        $this->assertNull(SnkKilkanaRichText::clean(null));
        $this->assertSame('', SnkKilkanaRichText::clean(''));
        $this->assertNull(SnkKilkanaRichText::cleanBlocks(null));
    }

    public function test_cleanBlocks_solo_toca_html_de_bloques_de_texto(): void
    {
        $blocks = [
            ['id' => 'a', 'type' => 'text', 'config' => ['html' => '<p onclick="x">hi</p>', 'text' => 'hi']],
            ['id' => 'b', 'type' => 'image', 'config' => ['src' => 'javascript:alert(1)']],
        ];
        $out = SnkKilkanaRichText::cleanBlocks($blocks);
        $this->assertStringNotContainsString('onclick', $out[0]['config']['html']);
        // Bloques no-texto quedan intactos: la sanitización de media/URL es otro contrato.
        $this->assertSame('javascript:alert(1)', $out[1]['config']['src']);
    }
}
