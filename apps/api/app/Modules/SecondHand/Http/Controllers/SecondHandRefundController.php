<?php

namespace App\Modules\SecondHand\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRefund;
use App\Modules\SecondHand\Services\SecondHandNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecondHandRefundController extends Controller
{
    public function __construct(
        private readonly AssetLedgerService $ledger,
        private readonly SecondHandNotificationService $notify,
    ) {}

    /**
     * 买家申请退款
     */
    public function request(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id, 403);
        abort_unless(in_array($order->status, ['paid', 'shipped'], true), 409, '当前订单状态不允许申请退款');

        $existing = SecondHandRefund::query()->where('order_id', $order->id)->first();
        abort_if($existing !== null && ! in_array($existing->status, ['seller_rejected', 'arbitrated_rejected'], true), 409, '退款申请已存在');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:26'],
        ]);

        $refund = DB::transaction(function () use ($order, $data, $existing) {
            if ($existing !== null) {
                // 重新申请（在被拒绝后可再次申请）
                $existing->update([
                    'amount' => $order->paid_amount,
                    'reason' => $data['reason'],
                    'images' => $data['images'] ?? [],
                    'status' => 'pending',
                    'seller_note' => null,
                    'seller_responded_at' => null,
                    'admin_note' => null,
                    'admin_user_id' => null,
                    'arbitrated_at' => null,
                    'auto_approve_at' => now()->addHours(24),
                ]);

                return $existing;
            }

            return SecondHandRefund::create([
                'order_id' => $order->id,
                'buyer_user_id' => $order->buyer_user_id,
                'seller_user_id' => $order->seller_user_id,
                'amount' => $order->paid_amount,
                'reason' => $data['reason'],
                'images' => $data['images'] ?? [],
                'status' => 'pending',
                'auto_approve_at' => now()->addHours(24),
            ]);
        });
        $refund->loadMissing('order');
        $this->notify->refundRequested($refund);

        return ApiResponse::success($this->present($refund), 201);
    }

    /**
     * 卖家响应退款（同意/拒绝）
     */
    public function respond(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->seller_user_id === $user->id, 403);

        $refund = SecondHandRefund::query()->where('order_id', $order->id)->where('status', 'pending')->firstOrFail();

        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'seller_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['action'] === 'approve') {
            DB::transaction(function () use ($order, $refund, $data) {
                $lockedOrder = SecondHandOrder::query()->lockForUpdate()->findOrFail($order->id);
                abort_unless(in_array($lockedOrder->status, ['paid', 'shipped'], true), 409, '订单状态已变更');

                // 退回买家钱包
                if ($refund->amount > 0) {
                    $buyer = User::query()->findOrFail($refund->buyer_user_id);
                    $this->ledger->changeWallet(
                        $buyer,
                        $refund->amount,
                        '二手闲置退款到账',
                        'second-hand-refund:'.$refund->public_id,
                        null,
                        'second_hand_refund',
                    );
                }

                $refund->update([
                    'status' => 'seller_approved',
                    'seller_note' => $data['seller_note'] ?? null,
                    'seller_responded_at' => now(),
                ]);
                $lockedOrder->update(['status' => 'refunded']);
                if ($lockedOrder->item()->whereIn('status', ['reserved', 'sold'])->exists()) {
                    $lockedOrder->item()->update(['status' => 'on_sale']);
                }
            });
        } else {
            $refund->update([
                'status' => 'seller_rejected',
                'seller_note' => $data['seller_note'] ?? null,
                'seller_responded_at' => now(),
            ]);
        }
        $refund = $refund->fresh(['order']);
        $this->notify->refundResponded($refund, $data['action'] === 'approve');

        return ApiResponse::success($this->present($refund));
    }

    /**
     * 查看退款详情
     */
    public function show(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id || $order->seller_user_id === $user->id, 403);

        $refund = SecondHandRefund::query()->where('order_id', $order->id)->firstOrFail();

        return ApiResponse::success($this->present($refund));
    }

    /**
     * 买家申请平台介入（在被卖家拒绝后）
     */
    public function escalate(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id, 403);

        $refund = SecondHandRefund::query()->where('order_id', $order->id)->where('status', 'seller_rejected')->firstOrFail();
        $refund->update(['status' => 'arbitrating']);

        return ApiResponse::success($this->present($refund));
    }

    // ---------- Admin ----------

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandRefund::query()->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $total = $query->count();
        $items = $query->forPage($page, $size)->with(['order', 'buyer', 'seller'])->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandRefund $r) => $this->present($r)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function arbitrate(Request $request, SecondHandRefund $refund): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'max:500'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();

        abort_unless(in_array($refund->status, ['pending', 'arbitrating', 'seller_rejected'], true), 409, '当前退款状态不可仲裁');

        if ($data['decision'] === 'approve') {
            DB::transaction(function () use ($refund, $data, $admin) {
                $order = SecondHandOrder::query()->lockForUpdate()->findOrFail($refund->order_id);
                abort_unless(in_array($order->status, ['paid', 'shipped', 'refunded'], true), 409, '订单状态已变更');

                if ($refund->amount > 0) {
                    $buyer = User::query()->findOrFail($refund->buyer_user_id);
                    $this->ledger->changeWallet(
                        $buyer,
                        $refund->amount,
                        '二手闲置平台仲裁退款到账',
                        'second-hand-refund-arbitrate:'.$refund->public_id,
                        $admin,
                        'second_hand_refund',
                    );
                }

                $refund->update([
                    'status' => 'arbitrated_approved',
                    'admin_note' => $data['note'],
                    'admin_user_id' => $admin->id,
                    'arbitrated_at' => now(),
                ]);
                $order->update(['status' => 'refunded']);
                if ($order->item()->whereIn('status', ['reserved', 'sold'])->exists()) {
                    $order->item()->update(['status' => 'on_sale']);
                }
            });
        } else {
            $refund->update([
                'status' => 'arbitrated_rejected',
                'admin_note' => $data['note'],
                'admin_user_id' => $admin->id,
                'arbitrated_at' => now(),
            ]);
        }
        $refund = $refund->fresh(['order']);
        $this->notify->refundArbitrated($refund, $data['decision'] === 'approve');

        return ApiResponse::success($this->present($refund));
    }

    /**
     * 超时自动同意退款（Command 调用）
     */
    public function autoApproveExpired(int $limit = 100): int
    {
        $ids = SecondHandRefund::query()
            ->where('status', 'pending')
            ->where('auto_approve_at', '<=', now())
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $refund = SecondHandRefund::query()->lockForUpdate()->findOrFail($id);
                    if ($refund->status !== 'pending') {
                        return;
                    }

                    $order = SecondHandOrder::query()->lockForUpdate()->findOrFail($refund->order_id);
                    if (! in_array($order->status, ['paid', 'shipped'], true)) {
                        return;
                    }

                    if ($refund->amount > 0) {
                        $buyer = User::query()->findOrFail($refund->buyer_user_id);
                        $this->ledger->changeWallet(
                            $buyer,
                            $refund->amount,
                            '二手闲置超时自动退款到账',
                            'second-hand-refund-auto:'.$refund->public_id,
                            null,
                            'second_hand_refund',
                        );
                    }

                    $refund->update([
                        'status' => 'seller_approved',
                        'seller_note' => '卖家未在24小时内处理，系统自动同意退款',
                        'seller_responded_at' => now(),
                    ]);
                    $order->update(['status' => 'refunded']);
                    if ($order->item()->whereIn('status', ['reserved', 'sold'])->exists()) {
                        $order->item()->update(['status' => 'on_sale']);
                    }
                    $this->notify->refundResponded($refund->fresh(['order']), true);
                });
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }

    private function present(SecondHandRefund $refund): array
    {
        return [
            'id' => $refund->public_id,
            'order_id' => $refund->order?->public_id,
            'amount' => round($refund->amount / 100, 2),
            'reason' => $refund->reason,
            'images' => $refund->images ?? [],
            'status' => $refund->status,
            'seller_note' => $refund->seller_note,
            'seller_responded_at' => $refund->seller_responded_at?->toIso8601String(),
            'admin_note' => $refund->admin_note,
            'arbitrated_at' => $refund->arbitrated_at?->toIso8601String(),
            'auto_approve_at' => $refund->auto_approve_at?->toIso8601String(),
            'buyer' => $this->presentUser($refund->buyer),
            'seller' => $this->presentUser($refund->seller),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->public_id, 'nickname' => $user->nickname, 'avatar' => $user->avatar_file_id];
    }
}
