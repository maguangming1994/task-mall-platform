<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointDebt;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Wallet\Models\UserBankCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAssetLedgerController extends Controller
{
    public function pointAccount(Request $request, AssetLedgerService $ledger): JsonResponse
    {
        $account = $ledger->account($request->user());
        $userId = $request->user()->id;
        $activeBatches = PointBatch::query()
            ->where('user_id', $userId)
            ->where('status', 'available')
            ->where('remaining_points', '>', 0)
            ->where('expires_at', '>', now());
        $next = (clone $activeBatches)->orderBy('expires_at')->first();

        return ApiResponse::success([
            'available_points' => $account->points_available, 'frozen_points' => $account->points_frozen,
            'debt_points' => $account->points_debt, 'spendable' => $account->points_debt === 0,
            'expiring_points' => $next?->remaining_points ?? 0, 'next_expiration_at' => $next?->expires_at?->toIso8601String(),
            'expiring_7_days' => (clone $activeBatches)->where('expires_at', '<=', now()->addDays(7))->sum('remaining_points'),
            'expiring_30_days' => (clone $activeBatches)->where('expires_at', '<=', now()->addDays(30))->sum('remaining_points'),
        ]);
    }

    public function pointBatches(Request $request): JsonResponse
    {
        $items = PointBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('remaining_points', '>', 0)
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PointBatch $batch) => [
                'id' => $batch->public_id,
                'source_code' => $batch->source_code,
                'issued_points' => $batch->issued_points,
                'remaining_points' => $batch->remaining_points,
                'frozen_points' => $batch->frozen_points,
                'status' => $batch->status,
                'issued_at' => $batch->issued_at?->toIso8601String(),
                'expires_at' => $batch->expires_at?->toIso8601String(),
                'days_remaining' => $batch->expires_at === null ? null : max(0, now()->startOfDay()->diffInDays($batch->expires_at->startOfDay(), false)),
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function pointTransactions(Request $request): JsonResponse
    {
        $items = PointTransaction::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get()->map(fn (PointTransaction $item) => [
            'id' => $item->public_id, 'type' => $item->transaction_type, 'source_code' => $item->source_code,
            'change_points' => $item->change_available, 'balance_after' => $item->available_after, 'debt_after' => $item->debt_after,
            'reason' => $item->reason, 'created_at' => $item->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function pointDebts(Request $request): JsonResponse
    {
        $items = PointDebt::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get()->map(fn (PointDebt $item) => [
            'id' => $item->public_id, 'source_code' => $item->source_code, 'source_business_id' => $item->source_business_id,
            'original_debt_points' => $item->original_debt_points, 'remaining_debt_points' => $item->remaining_debt_points,
            'status' => $item->status, 'reason' => $item->reason, 'created_at' => $item->created_at?->toIso8601String(),
            'settled_at' => $item->settled_at?->toIso8601String(),
        ]);

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function walletAccount(Request $request, AssetLedgerService $ledger): JsonResponse
    {
        $account = $ledger->account($request->user());

        return ApiResponse::success(['currency' => 'CNY', 'available_amount' => $account->wallet_available_cents, 'frozen_amount' => $account->wallet_frozen_cents, 'withdrawable_amount' => $account->wallet_available_cents]);
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $items = WalletTransaction::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get()->map(fn (WalletTransaction $item) => [
            'id' => $item->public_id, 'type' => $item->transaction_type, 'change_amount' => $item->change_available_cents,
            'balance_after' => $item->available_after_cents, 'business_type' => $item->business_type,
            'remark' => $item->reason, 'created_at' => $item->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $query = UserCoupon::query()->where('user_id', $request->user()->id);
        if ($status !== '') {
            $query->where('status', $status);
        }
        $items = $query->latest('id')->get()->map(fn (UserCoupon $coupon) => [
            'id' => $coupon->public_id, 'name' => $coupon->template_snapshot['name'],
            'cash_discount_amount' => $coupon->template_snapshot['cash_discount_cents'],
            'points_discount' => $coupon->template_snapshot['points_discount'],
            'valid_to' => $coupon->valid_to?->toIso8601String(), 'status' => $coupon->status,
        ]);

        return ApiResponse::success($items);
    }

    public function withdrawals(Request $request, WalletWithdrawalService $withdrawals): JsonResponse
    {
        $items = WalletWithdrawal::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get()
            ->map(fn (WalletWithdrawal $withdrawal) => $withdrawals->present($withdrawal));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function createWithdrawal(Request $request, WalletWithdrawalService $withdrawals): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:wechat_transfer,bank_card'],
            'bank_card_id' => ['nullable', 'string', 'size:26'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        $bankCard = null;
        if ($data['channel'] === 'bank_card') {
            if (empty($data['bank_card_id'])) {
                abort(422, '请选择提现银行卡。');
            }
            $bankCard = UserBankCard::query()
                ->where('public_id', $data['bank_card_id'])
                ->where('user_id', $request->user()->id)
                ->first();
            abort_if($bankCard === null, 422, '收款银行卡不存在或已解绑，请重新选择。');
        }

        $withdrawal = $withdrawals->create($request->user(), $data['amount'], $data['channel'], $key, $bankCard);

        return ApiResponse::success($withdrawals->present($withdrawal), 201);
    }

    public function withdrawalQuote(Request $request, WalletWithdrawalService $withdrawals): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($withdrawals->quote($data['amount']));
    }
}
