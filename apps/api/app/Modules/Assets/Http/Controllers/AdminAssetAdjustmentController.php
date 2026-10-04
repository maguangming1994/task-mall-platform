<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\AssetAdjustmentRequest;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Assets\Services\AssetAdjustmentService;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Assets\Services\WalletWithdrawalService;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAssetAdjustmentController extends Controller
{
    public function index(Request $request, AssetAdjustmentService $adjustments): JsonResponse
    {
        $status = $request->string('status', 'pending')->toString();
        $items = AssetAdjustmentRequest::query()->when($status !== '', fn ($query) => $query->where('status', $status))->latest('id')->limit(100)->get()
            ->map(fn (AssetAdjustmentRequest $item) => $adjustments->present($item));

        return ApiResponse::success(['items' => $items]);
    }

    public function userAssets(string $userId, AssetLedgerService $ledger): JsonResponse
    {
        $user = User::query()->where('public_id', $userId)->firstOrFail();
        $account = $ledger->account($user);

        return ApiResponse::success([
            'user_id' => $user->public_id,
            'points' => ['available' => $account->points_available, 'frozen' => $account->points_frozen, 'debt' => $account->points_debt],
            'wallet' => ['available_amount' => $account->wallet_available_cents, 'frozen_amount' => $account->wallet_frozen_cents, 'currency' => 'CNY'],
            'coupons' => ['available' => $account->coupon_available], 'version' => $account->version,
        ]);
    }

    public function store(Request $request, string $userId, AssetAdjustmentService $adjustments, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'asset_type' => ['required', 'in:points,wallet,coupon'],
            'action' => ['required', 'in:increase,decrease,clear,issue'],
            'amount' => ['required_unless:action,clear', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'coupon_template_id' => ['required_if:asset_type,coupon', 'nullable', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $user = User::query()->where('public_id', $userId)->firstOrFail();
        /** @var AdminUser $admin */ $admin = $request->user();
        $adjustment = $adjustments->request($admin, $user, $data, $this->key($request));
        $presented = $adjustments->present($adjustment);
        $audit->record($admin, 'asset_adjustment.request', 'user', $user->public_id, null, $presented, $data['reason']);

        return ApiResponse::success($presented, 201);
    }

    public function review(Request $request, AssetAdjustmentRequest $assetAdjustment, AssetAdjustmentService $adjustments, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['required', 'string', 'min:1', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $adjustments->review($admin, $assetAdjustment, $data['decision'], $data['note'], $this->key($request));
        $presented = $adjustments->present($result);
        $audit->record($admin, 'asset_adjustment.'.$data['decision'], 'asset_adjustment', $result->public_id, null, $presented, $data['note']);

        return ApiResponse::success($presented);
    }

    public function revokeCoupon(Request $request, UserCoupon $userCoupon, AssetLedgerService $ledger, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $coupon = $ledger->revokeCoupon($userCoupon, $data['reason'], $this->key($request), $admin);
        $audit->record($admin, 'user_coupon.revoke', 'user_coupon', $coupon->public_id, null, ['status' => $coupon->status], $data['reason']);

        return ApiResponse::success(['id' => $coupon->public_id, 'status' => $coupon->status]);
    }

    public function withdrawals(Request $request, WalletWithdrawalService $withdrawals): JsonResponse
    {
        $status = $request->string('status', 'reviewing')->toString();
        $items = WalletWithdrawal::query()->when($status !== '', fn ($query) => $query->where('status', $status))->latest('id')->limit(100)->get()
            ->map(fn (WalletWithdrawal $withdrawal) => $withdrawals->present($withdrawal));

        return ApiResponse::success(['items' => $items]);
    }

    public function reviewWithdrawal(Request $request, WalletWithdrawal $walletWithdrawal, WalletWithdrawalService $withdrawals, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['required', 'string', 'min:1', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $withdrawals->review($admin, $walletWithdrawal, $data['decision'], $data['note'], $this->key($request));
        $presented = $withdrawals->present($result);
        $audit->record($admin, 'wallet_withdrawal.'.$data['decision'], 'wallet_withdrawal', $result->public_id, null, $presented, $data['note']);

        return ApiResponse::success($presented);
    }

    public function syncWithdrawal(Request $request, WalletWithdrawal $walletWithdrawal, WalletWithdrawalService $withdrawals, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $withdrawals->sync($walletWithdrawal);
        $presented = $withdrawals->present($result);
        $audit->record($admin, 'wallet_withdrawal.sync', 'wallet_withdrawal', $result->public_id, null, $presented, '管理员主动查询出款渠道状态。');

        return ApiResponse::success($presented);
    }

    public function dispatchWithdrawal(Request $request, WalletWithdrawal $walletWithdrawal, WalletWithdrawalService $withdrawals, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $withdrawals->dispatch($walletWithdrawal);
        $presented = $withdrawals->present($result);
        $audit->record($admin, 'wallet_withdrawal.dispatch', 'wallet_withdrawal', $result->public_id, null, $presented, '管理员重试提交出款渠道。');

        return ApiResponse::success($presented);
    }

    public function manualWithdrawalResult(Request $request, WalletWithdrawal $walletWithdrawal, WalletWithdrawalService $withdrawals, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:paid,failed'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $withdrawals->recordManualResult(
            $admin,
            $walletWithdrawal,
            $data['decision'],
            (string) ($data['reference'] ?? ''),
            (string) ($data['note'] ?? ''),
        );
        $presented = $withdrawals->present($result);
        $audit->record($admin, 'wallet_withdrawal.manual_'.$data['decision'], 'wallet_withdrawal', $result->public_id, null, $presented, $data['note'] ?? $data['reference'] ?? null);

        return ApiResponse::success($presented);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
