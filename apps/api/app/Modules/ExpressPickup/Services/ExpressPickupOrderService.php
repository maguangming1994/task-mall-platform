<?php

namespace App\Modules\ExpressPickup\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupOrderEvent;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpressPickupOrderService
{
    public function __construct(private readonly ReceiptPrintService $receipts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, string $idempotencyKey): ExpressPickupOrder
    {
        if ($user->campus_id === null) {
            throw ValidationException::withMessages(['campus' => ['请先绑定学校后再发布代取需求。']]);
        }
        $hash = hash('sha256', 'express-pickup-create:'.$user->id.':'.$idempotencyKey);
        $existing = ExpressPickupOrder::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $reward = (int) $data['reward_cents'];

        return ExpressPickupOrder::query()->create([
            'order_no' => 'EP'.now()->format('YmdHis').strtoupper(Str::random(6)),
            'campus_id' => (int) $user->campus_id,
            'user_id' => $user->id,
            'status' => ExpressPickupOrder::STATUS_PENDING_PAYMENT,
            'pickup_site' => (string) $data['pickup_site'],
            'pickup_code' => (string) $data['pickup_code'],
            'package_info' => $data['package_info'] ?? null,
            'package_count' => (int) ($data['package_count'] ?? 1),
            'deliver_building' => (string) $data['deliver_building'],
            'deliver_address' => (string) $data['deliver_address'],
            'contact_name' => (string) $data['contact_name'],
            'contact_phone' => (string) $data['contact_phone'],
            'reward_cents' => $reward,
            'total_cents' => $reward,
            'expected_at' => $data['expected_at'] ?? null,
            'remark' => $data['remark'] ?? null,
            'idempotency_key_hash' => $hash,
        ]);
    }

    /**
     * 钱包支付：直接扣减可用余额并转入待接单。
     */
    public function payWithWallet(User $user, ExpressPickupOrder $order, string $idempotencyKey): ExpressPickupOrder
    {
        abort_unless($order->user_id === $user->id, 404);
        $key = hash('sha256', 'express-pickup-wallet:'.$order->public_id.':'.$idempotencyKey);

        $paid = DB::transaction(function () use ($user, $order, $key): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== ExpressPickupOrder::STATUS_PENDING_PAYMENT) {
                throw ValidationException::withMessages(['order' => ['订单当前不能支付。']]);
            }
            $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($account->wallet_available_cents < $locked->total_cents) {
                throw ValidationException::withMessages(['wallet' => ['余额不足，代取单不会部分扣款。']]);
            }
            $account->update([
                'wallet_available_cents' => $account->wallet_available_cents - $locked->total_cents,
                'version' => $account->version + 1,
            ]);
            WalletTransaction::query()->create([
                'user_id' => $user->id,
                'transaction_type' => 'express_pickup_payment',
                'change_available_cents' => -$locked->total_cents,
                'change_frozen_cents' => 0,
                'available_after_cents' => $account->wallet_available_cents,
                'frozen_after_cents' => $account->wallet_frozen_cents,
                'business_type' => 'express_pickup_order',
                'business_id' => $locked->public_id,
                'event_key_hash' => $key,
                'reason' => '快递代取跑腿费支付。',
            ]);

            return $this->markPaidLocked($locked, 'wallet', $locked->total_cents);
        });

        $this->receipts->printOrder($paid);

        return $paid->fresh();
    }

    /**
     * 支付渠道回调成功后调用（幂等）。
     */
    public function markPaid(ExpressPickupOrder $order, string $channel, int $amount): ExpressPickupOrder
    {
        $paid = $this->markPaidInTransaction($order, $channel, $amount);
        $this->receipts->printOrder($paid);

        return $paid->fresh();
    }

    /**
     * 在调用方的事务内完成支付状态流转，打印由调用方在事务提交后触发。
     */
    public function markPaidInTransaction(ExpressPickupOrder $order, string $channel, int $amount): ExpressPickupOrder
    {
        return DB::transaction(function () use ($order, $channel, $amount): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== ExpressPickupOrder::STATUS_PENDING_PAYMENT) {
                return $locked;
            }
            if ($amount !== $locked->total_cents) {
                throw ValidationException::withMessages(['payment' => ['支付金额与代取单不一致。']]);
            }

            return $this->markPaidLocked($locked, $channel, $amount);
        });
    }

    public function printReceipt(ExpressPickupOrder $order): void
    {
        $this->receipts->printOrder($order);
    }

    public function cancel(User $user, ExpressPickupOrder $order, ?string $reason = null): ExpressPickupOrder
    {
        abort_unless($order->user_id === $user->id, 404);
        $cancelled = DB::transaction(function () use ($order, $reason, $user): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, [ExpressPickupOrder::STATUS_PENDING_PAYMENT, ExpressPickupOrder::STATUS_PENDING_ACCEPT], true)) {
                throw ValidationException::withMessages(['order' => ['跑腿已接单，请联系客服处理取消。']]);
            }
            $this->cancelLocked($locked, 'user', $user->id, $reason ?? '用户主动取消');

            return $locked;
        });

        return $cancelled->fresh();
    }

    public function adminCancel(int $adminId, ExpressPickupOrder $order, string $reason): ExpressPickupOrder
    {
        $cancelled = DB::transaction(function () use ($order, $reason, $adminId): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($locked->status, [ExpressPickupOrder::STATUS_COMPLETED, ExpressPickupOrder::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages(['order' => ['该订单已终态，不能取消。']]);
            }
            $this->cancelLocked($locked, 'admin', $adminId, $reason);

            return $locked;
        });

        return $cancelled->fresh();
    }

    public function accept(User $runner, ExpressPickupOrder $order): ExpressPickupOrder
    {
        $this->assertActiveRunner($runner, (int) $order->campus_id);
        if ($order->user_id === $runner->id) {
            throw ValidationException::withMessages(['order' => ['不能接自己发布的代取单。']]);
        }

        return DB::transaction(function () use ($order, $runner): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== ExpressPickupOrder::STATUS_PENDING_ACCEPT) {
                throw ValidationException::withMessages(['order' => ['该订单当前不可接单。']]);
            }
            $this->transition($locked, ExpressPickupOrder::STATUS_ACCEPTED, 'accepted', 'runner', $runner->id);
            $locked->forceFill(['runner_user_id' => $runner->id, 'accepted_at' => now()])->save();

            return $locked->fresh();
        });
    }

    public function pickup(User $runner, ExpressPickupOrder $order): ExpressPickupOrder
    {
        return DB::transaction(function () use ($order, $runner): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertRunnerOwns($locked, $runner);
            if ($locked->status !== ExpressPickupOrder::STATUS_ACCEPTED) {
                throw ValidationException::withMessages(['order' => ['请先接单后再确认取件。']]);
            }
            $this->transition($locked, ExpressPickupOrder::STATUS_PICKED_UP, 'picked_up', 'runner', $runner->id);
            $locked->forceFill(['picked_up_at' => now()])->save();

            return $locked->fresh();
        });
    }

    public function deliver(User $runner, ExpressPickupOrder $order): ExpressPickupOrder
    {
        return DB::transaction(function () use ($order, $runner): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertRunnerOwns($locked, $runner);
            if ($locked->status !== ExpressPickupOrder::STATUS_PICKED_UP) {
                throw ValidationException::withMessages(['order' => ['请先确认取件后再标记送达。']]);
            }
            $this->transition($locked, ExpressPickupOrder::STATUS_DELIVERED, 'delivered', 'runner', $runner->id);
            $locked->forceFill(['delivered_at' => now()])->save();

            return $locked->fresh();
        });
    }

    public function confirm(User $user, ExpressPickupOrder $order): ExpressPickupOrder
    {
        abort_unless($order->user_id === $user->id, 404);

        return DB::transaction(function () use ($order, $user): ExpressPickupOrder {
            $locked = ExpressPickupOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== ExpressPickupOrder::STATUS_DELIVERED) {
                throw ValidationException::withMessages(['order' => ['当前订单还不能确认完成。']]);
            }
            $this->transition($locked, ExpressPickupOrder::STATUS_COMPLETED, 'completed', 'user', $user->id);
            $locked->forceFill(['completed_at' => now()])->save();

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ExpressPickupOrder $order, bool $maskSensitive = true): array
    {
        return [
            'id' => $order->public_id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'campus_id' => $order->campus_id,
            'pickup_site' => $order->pickup_site,
            'pickup_code' => $maskSensitive ? $this->maskCode((string) $order->pickup_code) : $order->pickup_code,
            'package_info' => $order->package_info,
            'package_count' => $order->package_count,
            'deliver_building' => $order->deliver_building,
            'deliver_address' => $order->deliver_address,
            'contact_name' => $order->contact_name,
            'contact_phone' => $maskSensitive ? $this->maskPhone((string) $order->contact_phone) : $order->contact_phone,
            'reward_cents' => $order->reward_cents,
            'total_cents' => $order->total_cents,
            'payment_method' => $order->payment_method,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'expected_at' => $order->expected_at?->toIso8601String(),
            'remark' => $order->remark,
            'runner_user_id' => $order->runner?->public_id,
            'runner_name' => $order->runner?->display_name,
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'picked_up_at' => $order->picked_up_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancel_reason' => $order->cancel_reason,
            'print_status' => $order->print_status,
            'printed_at' => $order->printed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function markPaidLocked(ExpressPickupOrder $locked, string $channel, int $amount): ExpressPickupOrder
    {
        $this->transition($locked, ExpressPickupOrder::STATUS_PENDING_ACCEPT, 'paid', 'system', null, '支付成功，等待跑腿接单。');
        $locked->forceFill([
            'status' => ExpressPickupOrder::STATUS_PENDING_ACCEPT,
            'payment_method' => $channel,
            'paid_at' => now(),
            'paid_amount_cents' => $amount,
        ])->save();

        return $locked->fresh();
    }

    private function cancelLocked(ExpressPickupOrder $locked, string $actorType, ?int $actorId, string $reason): void
    {
        $from = $locked->status;
        $locked->forceFill([
            'status' => ExpressPickupOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
        $this->log($locked, $from, ExpressPickupOrder::STATUS_CANCELLED, 'cancelled', $actorType, $actorId, $reason);

        if ($locked->paid_at !== null && $locked->refunded_at === null) {
            $this->refundLocked($locked);
        }
    }

    private function refundLocked(ExpressPickupOrder $locked): void
    {
        User::query()->lockForUpdate()->findOrFail($locked->user_id);
        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $locked->user_id]);
        $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
        $amount = (int) ($locked->paid_amount_cents ?? $locked->total_cents);
        $after = (int) $account->wallet_available_cents + $amount;
        $account->forceFill(['wallet_available_cents' => $after, 'version' => (int) $account->version + 1])->save();

        WalletTransaction::query()->create([
            'user_id' => $locked->user_id,
            'transaction_type' => 'express_pickup_refund',
            'change_available_cents' => $amount,
            'change_frozen_cents' => 0,
            'available_after_cents' => $after,
            'frozen_after_cents' => (int) $account->wallet_frozen_cents,
            'business_type' => 'express_pickup_order',
            'business_id' => $locked->public_id,
            'event_key_hash' => hash('sha256', 'express-pickup-refund:'.$locked->public_id),
            'reason' => '快递代取订单取消退款。',
        ]);
        $locked->forceFill(['refunded_at' => now()])->save();
    }

    private function transition(ExpressPickupOrder $order, string $to, string $event, string $actorType, ?int $actorId, ?string $reason = null): void
    {
        $this->log($order, $order->status, $to, $event, $actorType, $actorId, $reason);
    }

    private function log(ExpressPickupOrder $order, ?string $from, string $to, string $event, string $actorType, ?int $actorId, ?string $reason = null): void
    {
        ExpressPickupOrderEvent::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
    }

    private function assertRunnerOwns(ExpressPickupOrder $order, User $runner): void
    {
        if ((int) $order->runner_user_id !== $runner->id) {
            throw ValidationException::withMessages(['order' => ['只有接单跑腿可以更新该订单。']]);
        }
    }

    private function assertActiveRunner(User $runner, int $campusId): void
    {
        $exists = ExpressPickupRunner::query()
            ->where('campus_id', $campusId)
            ->where('user_id', $runner->id)
            ->where('status', 'active')
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['runner' => ['你还没有该学校的跑腿资格，请先申请。']]);
        }
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 7 ? substr($phone, 0, 3).'****'.substr($phone, -4) : $phone;
    }

    private function maskCode(string $code): string
    {
        $length = strlen($code);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return substr($code, 0, 1).str_repeat('*', $length - 2).substr($code, -1);
    }
}
