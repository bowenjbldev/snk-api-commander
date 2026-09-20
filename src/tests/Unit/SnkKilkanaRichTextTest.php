<?php

namespace Tests\Unit;

use Packages\Kilkana\Support\SnkKilkanaRichText;
use PHPUnit\Framework\TestCase;

class SnkKilkanaRichTextTest extends TestCase
{
    public function test_keeps_allowed_formatting(): void
    {
        // HTMLPurifier normaliza el style con `;` final; el resto se conserva íntegro.
        $html = '<p style="text-align:center">Hola <strong>mundo</strong> <em>y</em> <s>tachado</s> <code>x</code></p>';
        $expected = '<p style="text-align:center;">Hola <strong>mundo</strong> <em>y</em> <s>tachado</s> <code>x</code></p>';
        $this->assertSame($expected, SnkKilkanaRichText::clean($html));
    }

    public function test_removes_scripts_and_handlers(): void
    {
        $out = SnkKilkanaRichText::clean('<p onclick="alert(1)">hola</p><script>alert(2)</script><img src=x onerror=alert(3)>');
        $this->assertStringNotContainsStringIgnoringCase('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('hola', $out);
    }

    public function test_keeps_web_and_mail_links(): void
    {
        $html = '<a href="https://eldiario.example/pleno">el pleno</a> y <a href="mailto:redaccion@eldiario.example">la redacción</a>';
        $this->assertSame($html, SnkKilkanaRichText::clean($html));
    }

    public function test_blocks_javascript_and_data_links(): void
    {
        // El enlace pierde el destino y el texto sobrevive.
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4='] as $href) {
            $out = SnkKilkanaRichText::clean('<a href="'.$href.'">click</a>');
            $this->assertStringNotContainsString('javascript', $out);
            $this->assertStringNotContainsString('data:', $out);
            $this->assertStringContainsString('click', $out);
        }
    }

    public function test_only_allows_text_align_on_paragraphs(): void
    {
        $out = SnkKilkanaRichText::clean('<p style="text-align:right;color:red;position:fixed">x</p>');
        $this->assertStringContainsString('text-align:right', $out);
        $this->assertStringNotContainsString('color', $out);
        $this->assertStringNotContainsString('position', $out);
    }

    public function test_null_and_empty_pass_through_unchanged(): void
    {
        $this->assertNull(SnkKilkanaRichText::clean(null));
        $this->assertSame('', SnkKilkanaRichText::clean(''));
        $this->assertNull(SnkKilkanaRichText::cleanBlocks(null));
    }

    public function test_clean_blocks_only_touches_html_in_text_blocks(): void
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
