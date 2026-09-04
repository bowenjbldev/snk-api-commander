<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaNewsletterService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/** Los números del boletín con hora cumplida salen solos, periódico a periódico. */
class SnkKilkanaNewsletterSendDueCommand extends Command
{
    protected $signature = 'kilkana:newsletter-send-due';

    protected $description = 'Send scheduled Kilkana newsletter issues whose time has come, across all provisioned tenants';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaNewsletterService $newsletter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(fn (string $database, string $client) => $this->newsletter->sendDue($database, $client));

        foreach ($run['results'] as $database => $sent) {
            if ($sent) {
                $this->info("[{$database}] sent {$sent} issue(s)");
            }
        }
        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        return count($run['errors']) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
