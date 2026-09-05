<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Support\CoreMeteringBufferSupport;
use Tests\TestCase;

/** El metering escribe al momento con lote 1 y en bloque cuando el lote se llena. */
class CoreMeteringBufferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_batch_is_written_when_it_fills_and_flushed_on_demand(): void
    {
        config(['core.metering.batch' => 3, 'core.metering.batch_seconds' => 3600]);
        $buffer = app(CoreMeteringBufferSupport::class);

        $buffer->add($this->row());
        $buffer->add($this->row());
        $this->assertSame(2, $buffer->pending());
        $this->assertSame(0, CommanderRequestLogModel::query()->count());

        $buffer->add($this->row());
        $this->assertSame(0, $buffer->pending());
        $this->assertSame(3, CommanderRequestLogModel::query()->count());

        $buffer->add($this->row());
        $buffer->flush();
        $this->assertSame(4, CommanderRequestLogModel::query()->count());
    }

    public function test_batch_of_one_writes_immediately(): void
    {
        config(['core.metering.batch' => 1]);
        app(CoreMeteringBufferSupport::class)->add($this->row());

        $this->assertSame(1, CommanderRequestLogModel::query()->count());
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $now = now('UTC');

        return [
            'instance_id' => 1, 'created_at' => $now, 'updated_at' => $now, 'method' => 'GET',
            'route' => 'x', 'path' => 'x', 'status' => 200, 'duration_ms' => 1, 'user_id' => null,
            'token_id' => null, 'ip' => '127.0.0.1', 'request_bytes' => 0, 'response_bytes' => 0, 'memory_kb' => 1,
        ];
    }
}
