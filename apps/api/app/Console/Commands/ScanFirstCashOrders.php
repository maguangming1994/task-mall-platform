<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Order\Models\Order;
use Illuminate\Console\Command;

class ScanFirstCashOrders extends Command
{
    protected $signature = 'growth:scan-first-cash-orders {--limit=500}';

    protected $description = 'Record the first completed order with actual cash for referred users.';

    public function handle(ReferralService $referrals): int
    {
        Order::query()->where('status', 'completed')->whereRaw('(paid_wallet_amount + paid_external_amount) > 0')->orderBy('paid_at')->orderBy('id')->limit((int) $this->option('limit'))->get()->each(function (Order $order) use ($referrals) {
            $referrals->recordFirstCashOrder(User::query()->findOrFail($order->user_id), $order->public_id, $order->paid_wallet_amount + $order->paid_external_amount, $order->status);
        });

        return self::SUCCESS;
    }
}
