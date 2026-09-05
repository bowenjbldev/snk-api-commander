<?php

namespace Packages\Core\Listeners;

use Packages\Core\Support\CoreMeteringBufferSupport;

/** Al parar un worker de Octane, lo que quede en la cola de metering se escribe. */
class CoreFlushMeteringListener
{
    public function handle(mixed $event): void
    {
        try {
            app(CoreMeteringBufferSupport::class)->flush();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
