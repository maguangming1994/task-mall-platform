<?php

namespace App\Console\Commands;

use App\Modules\ExpressPickup\Services\ReceiptPrintService;
use Illuminate\Console\Command;

class RetryExpressPickupPrints extends Command
{
    protected $signature = 'express-pickup:retry-prints {--limit=50}';

    protected $description = 'Retry failed express pickup receipt print jobs.';

    public function handle(ReceiptPrintService $receipts): int
    {
        $this->info('Retried express pickup print jobs: '.$receipts->retryFailed((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
