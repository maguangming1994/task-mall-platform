<?php

namespace App\Modules\SecondHand\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\SecondHand\Models\SecondHandCategory;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\SecondHand\Services\SecondHandNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecondHandItemController extends Controller
{
    public function __construct(private readonly SecondHandNotificationService $notify) {}

    private const CONDITIONS = [
        'new' => '全新',
        'like_new' => '几乎全新',
        'very_good' => '轻微使用',
        'good' => '正常使用',
        'fair' => '明显使用',
    ];

    public function categories(): JsonResponse
    {
        $items = SecondHandCategory::query()->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SecondHandCategory $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
                'icon' => $c->icon,
                'sort_order' => (int) $c->sort_order,
            ]);

        return ApiResponse::success(['items' => $items, 'conditions' => self::CONDITIONS]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'string'],
            'keyword' => ['nullable', 'string', 'max:60'],
            'condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'trade_type' => ['nullable', Rule::in(['meetup', 'mail', 'both'])],
            'seller' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', Rule::in(['latest', 'price_asc', 'price_desc'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandItem::query()->where('status', 'on_sale')->where('audit_status', 'approved');
        if (! empty($data['category_id'])) {
            $query->whereHas('category', fn ($q) => $q->where('public_id', $data['category_id']));
        }
        if (! empty($data['keyword'])) {
            $kw = $data['keyword'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('description', 'like', "%{$kw}%"));
        }
        if (! empty($data['condition'])) {
            $query->where('condition', $data['condition']);
        }
        if (! empty($data['trade_type'])) {
            $tt = $data['trade_type'];
            $query->where(fn ($q) => $q->where('trade_type', $tt)->orWhere('trade_type', 'both'));
        }

        if (! empty($data['seller'])) {
            $query->whereHas('seller', fn ($q) => $q->where('public_id', $data['seller']));
        }

        $sort = $data['sort'] ?? 'latest';
        if ($sort === 'price_asc') {
            $query->orderBy('price');
        } elseif ($sort === 'price_desc') {
            $query->orderByDesc('price');
        } else {
            $query->latest('id');
        }

        $total = $query->count();
        $items = $query->forPage($page, $size)->with('seller')->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->present($i)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless(in_array($item->status, ['on_sale', 'reserved', 'sold'], true), 404);
        $item->increment('view_count');
        $item->loadMissing(['seller', 'category']);
        $isOwner = $request->user()?->id === $item->seller_user_id;

        // 非本人查看时，审核不通过的商品不可见
        abort_unless($isOwner || $item->audit_status === 'approved', 404);

        return ApiResponse::success($this->present($item, true, $isOwner));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateInput($request);
        $user = $request->user();
        $item = DB::transaction(function () use ($user, $data, $request) {
            return SecondHandItem::create([
                'campus_id' => $user->campus_id,
                'seller_user_id' => $user->id,
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'price' => (int) round(($data['price'] ?? 0) * 100),
                'condition' => $data['condition'] ?? 'like_new',
                'cover_file_public_id' => $data['cover'] ?? null,
                'images' => $data['images'] ?? [],
                'trade_type' => $data['trade_type'] ?? 'meetup',
                'location' => $data['location'] ?? null,
                'status' => 'on_sale',
                'audit_status' => 'pending',
                'idempotency_key_hash' => hash('sha256', $request->header('Idempotency-Key') ?: ($request->ip().'|'.$user->id.'|'.microtime(true))),
            ]);
        });
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true), 201);
    }

    public function update(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        abort_unless(in_array($item->status, ['on_sale', 'reserved'], true), 409, '已售出或已下架的商品不能编辑');
        $data = $this->validateInput($request);

        $item->update([
            'category_id' => $data['category_id'] ?? $item->category_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => (int) round(($data['price'] ?? 0) * 100),
            'condition' => $data['condition'] ?? 'like_new',
            'cover_file_public_id' => $data['cover'] ?? $item->cover_file_public_id,
            'images' => $data['images'] ?? $item->images,
            'trade_type' => $data['trade_type'] ?? 'meetup',
            'location' => $data['location'] ?? null,
        ]);
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true));
    }

    public function destroy(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        $item->update(['status' => 'off_shelf']);

        return ApiResponse::success(['id' => $item->public_id, 'status' => 'off_shelf']);
    }

    public function relist(Request $request, SecondHandItem $item): JsonResponse
    {
        abort_unless($request->user()->id === $item->seller_user_id, 403);
        abort_unless($item->status === 'off_shelf', 409, '仅已下架的商品可以重新上架');

        // 曾通过审核的直接恢复在售；被驳回或未审核过的重新进入审核队列
        $auditStatus = $item->audit_status === 'approved' ? 'approved' : 'pending';
        $item->update(['status' => 'on_sale', 'audit_status' => $auditStatus]);
        $item->loadMissing(['seller', 'category']);

        return ApiResponse::success($this->present($item, true, true));
    }

    public function sellerProfile(string $user): JsonResponse
    {
        $seller = User::query()->where('public_id', $user)->firstOrFail();

        $onSaleCount = SecondHandItem::query()
            ->where('seller_user_id', $seller->id)
            ->where('status', 'on_sale')->where('audit_status', 'approved')->count();
        $soldCount = SecondHandItem::query()
            ->where('seller_user_id', $seller->id)->where('status', 'sold')->count();

        $ratingQuery = SecondHandRating::query()->where('ratee_user_id', $seller->id);
        $ratingCount = (clone $ratingQuery)->count();
        $average = $ratingCount > 0 ? round((float) (clone $ratingQuery)->avg('score'), 1) : null;
        $goodCount = (clone $ratingQuery)->where('score', '>=', 4)->count();
        $goodRate = $ratingCount > 0 ? (int) round($goodCount / $ratingCount * 100) : null;

        $identityVerified = UserIdentityVerification::query()
            ->where('user_id', $seller->id)->where('status', 'verified')->exists();

        return ApiResponse::success([
            'id' => $seller->public_id,
            'nickname' => $seller->nickname,
            'avatar' => $seller->avatar_file_id,
            'campus_name' => $seller->campus?->name,
            'identity_verified' => $identityVerified,
            'on_sale_count' => $onSaleCount,
            'sold_count' => $soldCount,
            'rating_average' => $average,
            'rating_count' => $ratingCount,
            'good_rate_percent' => $goodRate,
        ]);
    }

    // ---------- Admin 审核 ----------

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audit_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandItem::query()
            ->when($data['audit_status'] ?? null, fn ($q, $s) => $q->where('audit_status', $s))
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)
            ->with(['seller', 'category', 'reviewer'])
            ->get();

        // 批量汇总卖家信用/在售成交/实名信息，供移动与后台审核卡片展示（避免 N+1）
        $sellerStats = $this->batchSellerStats($items->pluck('seller_user_id')->all());

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->presentAdminItem($i, $sellerStats[$i->seller_user_id] ?? [])),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function adminShow(SecondHandItem $item): JsonResponse
    {
        $item->loadMissing(['seller', 'category', 'reviewer']);
        $sellerStats = $this->batchSellerStats([$item->seller_user_id]);

        return ApiResponse::success($this->presentAdminItem($item, $sellerStats[$item->seller_user_id] ?? []));
    }

    public function adminReview(Request $request, SecondHandItem $item, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->presentAdminItem($item);

        // 事务内行锁 + 终态判定，避免并发重复审核（TOCTOU）导致双写、双通知
        $finished = false;
        DB::transaction(function () use ($item, $data, $admin, &$finished): void {
            $locked = SecondHandItem::query()->lockForUpdate()->find($item->id);
            if ($locked->audit_status !== 'pending') {
                $finished = true;

                return;
            }

            if ($data['decision'] === 'approve') {
                $locked->update([
                    'audit_status' => 'approved',
                    'rejected_reason' => null,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);
            } else {
                $locked->update([
                    'audit_status' => 'rejected',
                    'rejected_reason' => $data['reason'] ?? '未通过审核',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);
            }
            $item->setRawAttributes($locked->getAttributes(), true);
        });

        if ($finished) {
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该商品已经完成审核，不能重复操作。',
                409,
            );
        }

        $item->loadMissing(['seller', 'category', 'reviewer']);
        $sellerStats = $this->batchSellerStats([$item->seller_user_id]);
        $after = $this->presentAdminItem($item, $sellerStats[$item->seller_user_id] ?? []);

        $audit->record(
            $admin,
            'second_hand.'.$data['decision'],
            'second_hand_item',
            $item->public_id,
            $before,
            $after,
            $data['reason'] ?? null,
        );
        $this->notify->itemReviewed($item, $data['decision'] === 'approve', $data['reason'] ?? null);

        return ApiResponse::success($after);
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', Rule::in(['on_sale', 'reserved', 'sold', 'off_shelf'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = SecondHandItem::query()->where('seller_user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandItem $i) => $this->present($i)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'category_id' => ['nullable', 'string'],
            'cover' => ['nullable', 'string', 'max:26'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:26'],
            'trade_type' => ['nullable', Rule::in(['meetup', 'mail', 'both'])],
            'location' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function present(SecondHandItem $item, bool $detail = false, bool $isOwner = false): array
    {
        $base = [
            'id' => $item->public_id,
            'title' => $item->title,
            'price' => round($item->price / 100, 2),
            'price_fen' => $item->price,
            'condition' => $item->condition,
            'condition_label' => self::CONDITIONS[$item->condition] ?? $item->condition,
            'cover' => $item->cover_file_public_id,
            'images' => $item->images ?? [],
            'status' => $item->status,
            'audit_status' => $item->audit_status,
            'rejected_reason' => $item->rejected_reason,
            'trade_type' => $item->trade_type,
            'location' => $item->location,
            'view_count' => (int) $item->view_count,
            'created_at' => $item->created_at?->toIso8601String(),
            'category' => $item->category ? ['id' => $item->category->public_id, 'name' => $item->category->name] : null,
            'seller' => $this->presentUser($item->seller),
        ];

        if ($detail) {
            $base['description'] = $item->description;
            $base['favorite_count'] = (int) $item->favorite_count;
            $base['is_owner'] = $isOwner;
        }

        return $base;
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar_file_id,
        ];
    }

    /**
     * 管理端（后台/移动工作台）商品视图：在用户视图基础上补充卖家信用资料与审核留痕。
     *
     * @param  array<string, mixed>  $sellerStats  batchSellerStats() 预加载的卖家统计
     */
    private function presentAdminItem(SecondHandItem $item, array $sellerStats = []): array
    {
        $data = $this->present($item, true, false);
        $seller = $data['seller'] !== null ? array_merge($data['seller'], [
            'identity_verified' => (bool) ($sellerStats['identity_verified'] ?? false),
            'on_sale_count' => (int) ($sellerStats['on_sale_count'] ?? 0),
            'sold_count' => (int) ($sellerStats['sold_count'] ?? 0),
            'rating_average' => isset($sellerStats['rating_average']) ? round((float) $sellerStats['rating_average'], 1) : null,
            'rating_count' => (int) ($sellerStats['rating_count'] ?? 0),
        ]) : null;

        $data['seller'] = $seller;
        $data['submitted_at'] = $item->created_at?->toIso8601String();
        $data['reviewed_at'] = $item->reviewed_at?->toIso8601String();
        $data['reviewed_by_name'] = $item->relationLoaded('reviewer')
            ? $item->reviewer?->display_name
            : null;

        return $data;
    }

    /**
     * 批量统计卖家信用资料。
     *
     * @param  array<int, int|null>  $userIds
     * @return array<int, array<string, mixed>> 以 user_id 为键
     */
    private function batchSellerStats(array $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $ratings = SecondHandRating::query()
            ->whereIn('ratee_user_id', $ids)
            ->selectRaw('ratee_user_id, AVG(score) AS avg_score, COUNT(*) AS cnt')
            ->groupBy('ratee_user_id')
            ->get()
            ->keyBy('ratee_user_id');
        $itemStats = SecondHandItem::query()
            ->whereIn('seller_user_id', $ids)
            ->selectRaw(
                'seller_user_id, '
                ."SUM(CASE WHEN status = 'on_sale' AND audit_status = 'approved' THEN 1 ELSE 0 END) AS on_sale_count, "
                ."SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) AS sold_count",
            )
            ->groupBy('seller_user_id')
            ->get()
            ->keyBy('seller_user_id');
        $verifiedIds = UserIdentityVerification::query()
            ->whereIn('user_id', $ids)->where('status', 'verified')
            ->pluck('user_id')->unique();

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'identity_verified' => $verifiedIds->contains($id),
                'on_sale_count' => (int) ($itemStats[$id]->on_sale_count ?? 0),
                'sold_count' => (int) ($itemStats[$id]->sold_count ?? 0),
                'rating_average' => $ratings[$id]->avg_score ?? null,
                'rating_count' => (int) ($ratings[$id]->cnt ?? 0),
            ];
        }

        return $result;
    }
}
