<?php

namespace App\Console\Commands;

use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use App\Modules\ExpressPickup\Services\ExpressPickupRefundService;
use Illuminate\Console\Command;

class ExpireExpressPickupOrders extends Command
{
    protected $signature = 'express-pickup:expire-orders {--limit=100}';

    protected $description = 'Expire unpaid express pickup orders, cancel stale pending orders with refunds and auto-confirm delivered orders.';

    public function handle(ExpressPickupOrderService $orders, ExpressPickupRefundService $refunds): int
    {
        $limit = (int) $this->option('limit');
        $this->info('Expired unpaid express pickup orders: '.$orders->expireUnpaid($limit));
        $this->info('Cancelled stale pending accept orders: '.$orders->cancelStalePendingAccept($limit));
        $this->info('Auto confirmed delivered orders: '.$orders->autoConfirmDelivered($limit));
        $this->info('Synced express pickup refunds: '.$refunds->syncPending($limit));

        return self::SUCCESS;
    }
}
