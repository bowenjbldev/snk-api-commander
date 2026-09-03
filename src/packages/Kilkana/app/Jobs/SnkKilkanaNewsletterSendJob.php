<?php

namespace Packages\Kilkana\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Packages\Kilkana\Services\SnkKilkanaNewsletterService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * Una tanda del envío de un número: unos suscriptores de un periódico.
 *
 * Nace en la petición que pulsó «Send» y corre en el worker, que no sabe de ningún periódico:
 * por eso lleva la base y el subdominio, y se conecta al inquilino antes de tocar nada. Un
 * correo que falla no tumba la tanda: se apunta y se sigue, que es lo que hace un envío.
 *
 * @param  list<int>  $subscriberIds
 */
class SnkKilkanaNewsletterSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $database,
        public readonly string $client,
        public readonly int $issueId,
        public readonly array $subscriberIds,
    ) {}

    public function handle(SnkKilkanaTenantRunnerService $tenants, SnkKilkanaNewsletterService $newsletter): void
    {
        $tenants->on($this->database, fn () => $newsletter->deliver($this->issueId, $this->client, $this->subscriberIds));
    }
}
