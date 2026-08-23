<?php

namespace App\Modules\Order\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\AdminOrderPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function index(Request $request, AdminOrderPresenter $presenter, PhoneVerificationService $phoneVerification): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'fulfillment_type' => ['sometimes', 'nullable', 'in:logistics,no_logistics,virtual_delivery'],
            'cash_payment_method' => ['sometimes', 'nullable', 'in:wallet,wechat'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:created_from'],
        ]);

        $query = Order::query()->with(['user', 'items', 'fulfillments', 'refunds', 'exchanges']);
        $this->applyFilters($query, $filters, $phoneVerification);

        $summaryQuery = clone $query;
        $statusCounts = (clone $summaryQuery)
            ->reorder()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value);

        $paginator = $query->latest('orders.id')->paginate((int) ($filters['page_size'] ?? 20));
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success([
            'items' => $presenter->presentMany($paginator->getCollection(), $admin->hasPermission('user.view_sensitive')),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total' => $statusCounts->sum(),
                'status_counts' => $statusCounts,
                'pending_fulfillment' => (int) ($statusCounts->get('pending_fulfillment') ?? 0),
                'paid_cash_amount' => (int) (clone $summaryQuery)
                    ->reorder()
                    ->selectRaw('COALESCE(SUM(paid_wallet_amount + paid_external_amount), 0) AS aggregate')
                    ->value('aggregate'),
                'refunded_cash_amount' => (int) (clone $summaryQuery)
                    ->reorder()
                    ->selectRaw('COALESCE(SUM(refunded_wallet_amount + refunded_external_amount), 0) AS aggregate')
                    ->value('aggregate'),
            ],
        ]);
    }

    public function show(Request $request, Order $order, AdminOrderPresenter $presenter): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $items = $presenter->presentMany(
            new Collection([$order]),
            $admin->hasPermission('user.view_sensitive'),
            true,
        );

        return ApiResponse::success($items->first());
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters, PhoneVerificationService $phoneVerification): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('orders.status', $status))
            ->when($filters['fulfillment_type'] ?? null, fn (Builder $builder, string $type) => $builder->where('orders.fulfillment_type', $type))
            ->when($filters['cash_payment_method'] ?? null, fn (Builder $builder, string $method) => $builder->where('orders.cash_payment_method', $method))
            ->when($filters['created_from'] ?? null, fn (Builder $builder, string $date) => $builder->where('orders.created_at', '>=', $date))
            ->when($filters['created_to'] ?? null, fn (Builder $builder, string $date) => $builder->where('orders.created_at', '<=', $date));

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $normalizedPhone = preg_replace('/[^0-9+]/', '', $search) ?? $search;
        $query->where(function (Builder $builder) use ($search, $normalizedPhone, $phoneVerification): void {
            $builder
                ->where('orders.order_no', 'like', '%'.$search.'%')
                ->orWhere('orders.public_id', $search)
                ->orWhereHas('user', function (Builder $userQuery) use ($search, $normalizedPhone, $phoneVerification): void {
                    $userQuery->where('public_id', $search)->orWhere('nickname', 'like', '%'.$search.'%');
                    if (preg_match('/^\+?[0-9]{7,20}$/', $normalizedPhone) === 1) {
                        $userQuery->orWhere('phone_hash', $phoneVerification->phoneHash($normalizedPhone));
                    }
                });
        });
    }
}
