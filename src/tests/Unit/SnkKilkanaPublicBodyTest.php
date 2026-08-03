<?php

namespace Tests\Unit;

use Packages\Kilkana\Models\SnkKilkanaPostModel;
use PHPUnit\Framework\TestCase;

class SnkKilkanaPublicBodyTest extends TestCase
{
    public function test_free_keeps_the_body_and_closed_content_stops_at_the_wall(): void
    {
        $blocks = [
            ['id' => 'intro', 'type' => 'text', 'config' => ['text' => 'Teaser']],
            ['id' => 'wall', 'type' => 'paywall', 'config' => []],
            ['id' => 'paid', 'type' => 'text', 'config' => ['text' => 'Private']],
        ];

        $free = new SnkKilkanaPostModel(['content_tier' => 'free', 'body_blocks' => $blocks]);
        $locked = new SnkKilkanaPostModel(['content_tier' => 'locked', 'body_blocks' => $blocks]);
        $withoutWall = new SnkKilkanaPostModel(['content_tier' => 'metered', 'body_blocks' => [$blocks[0]]]);

        $this->assertSame($blocks, $free->publicBodyBlocks());
        $this->assertSame(array_slice($blocks, 0, 2), $locked->publicBodyBlocks());
        $this->assertSame('paywall', $withoutWall->publicBodyBlocks()[0]['type']);
    }
}
