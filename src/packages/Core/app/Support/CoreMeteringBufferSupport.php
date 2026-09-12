<?php

namespace Packages\Core\Support;

use Packages\Commander\Models\CommanderRequestLogModel;

/**
 * Cola en memoria de filas de metering, una por worker. En un worker persistente el INSERT de
 * cada petición corre antes de cerrar la respuesta y la encarece; aquí se acumulan y se escriben
 * en lote cuando llegan a `core.metering.batch` filas o pasan `core.metering.batch_seconds`
 * segundos desde la primera. Con lote 1 —FPM, consola, pruebas— se escribe al momento. Un worker
 * que muere pierde como mucho un lote: es un registro de uso, no un libro mayor. La edad solo se
 * mira al encolar, así que un worker ocioso retiene su lote hasta su siguiente petición o hasta
 * pararse. Va en `octane.warm`: resuelto dentro de una petición viviría en su sandbox.
 */
class CoreMeteringBufferSupport
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?float $since = null;

    /** @param array<string, mixed> $row */
    public function add(array $row): void
    {
        $this->rows[] = $row;
        $this->since ??= microtime(true);

        $size = max(1, (int) config('core.metering.batch', 1));
        $age = (float) config('core.metering.batch_seconds', 5);

        if (count($this->rows) >= $size || microtime(true) - $this->since >= $age) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }

        $rows = $this->rows;
        $this->rows = [];
        $this->since = null;

        CommanderRequestLogModel::query()->insert($rows);
    }

    public function pending(): int
    {
        return count($this->rows);
    }
}
