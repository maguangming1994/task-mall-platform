<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Models\CampusMembershipChange;
use App\Modules\Campus\Services\CampusBrandingUrl;
use App\Modules\Campus\Services\CampusLocationResolver;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class CampusController extends Controller
{
    public function __construct(
        private readonly CampusLocationResolver $locations,
        private readonly CampusBrandingUrl $branding,
        private readonly SystemSettingService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'province' => ['sometimes', 'string', 'max:60'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(['items' => $this->list($data)]);
    }

    public function nearby(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'province' => ['sometimes', 'string', 'max:60'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(['items' => $this->list($data, true)]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, array<string, mixed>>
     */
    private function list(array $data, bool $nearby = false): Collection
    {
        $query = Campus::query()
            ->with(['emblemAsset', 'logoAsset'])
            ->where('status', 'active')
            ->whereIn('catalog_status', ['manual', 'listed'])
            ->when(isset($data['province']), fn ($query) => $query->where('province', $data['province']))
            ->when(isset($data['keyword']), function ($query) use ($data): void {
                $keyword = '%'.$data['keyword'].'%';
                $query->where(fn ($nested) => $nested
                    ->where('public_id', 'like', $keyword)
                    ->orWhere('code', 'like', $keyword)
                    ->orWhere('name', 'like', $keyword)
                    ->orWhere('city', 'like', $keyword)
                    ->orWhere('district', 'like', $keyword))
                    ->orderByRaw(
                        'CASE WHEN public_id = ? OR code = ? THEN 0 ELSE 1 END',
                        [$data['keyword'], $data['keyword']],
                    );
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        $limit = (int) ($data['limit'] ?? 30);
        $located = $query
            ->when(! $nearby, fn ($query) => $query->limit($limit))
            ->get()
            ->map(function (Campus $campus) use ($data, $nearby): array {
                $location = $this->locations->resolve($campus, $nearby ? (float) $data['latitude'] : null, $nearby ? (float) $data['longitude'] : null);
                // City centroids help discover schools, but are not a campus location.
                // Never present the same city-centre distance as an exact school distance.
                $sortDistance = $nearby && $location['accuracy'] !== 'unavailable'
                    ? CampusLocationResolver::distance(
                        (float) $data['latitude'],
                        (float) $data['longitude'],
                        $location['latitude'],
                        $location['longitude'],
                    )
                    : null;

                $distance = in_array($location['accuracy'], ['campus', 'reference'], true) ? $sortDistance : null;

                return compact('campus', 'location', 'distance', 'sortDistance');
            });

        if ($nearby) {
            $located = $located->sortBy(function (array $item): array {
                $distance = $item['sortDistance'] ?? PHP_FLOAT_MAX;
                // Known local campus points must not be buried by dozens of identical city centroids.
                $group = $item['distance'] !== null ? ($distance <= 50 ? 0 : 2) : ($distance <= 50 ? 1 : 3);

                return [$group, $distance];
            });
        }

        return $located
            ->take($limit)
            ->map(fn (array $item) => $this->present($item['campus'], $item['location'], $item['distance']))
            ->values();
    }

    public function select(Request $request): JsonResponse
    {
        $currentYear = (int) date('Y');
        $data = $request->validate([
            'campus_id' => [
                'required',
                Rule::exists('campuses', 'public_id')
                    ->where('status', 'active')
                    ->whereIn('catalog_status', ['manual', 'listed']),
            ],
            // 档案三字段（选填；grade_year 须在 1900~当年区间）
            'grade_year' => ['sometimes', 'integer', 'min:1900', 'max:'.$currentYear],
            'major' => ['sometimes', 'string', 'max:30'],
            'college' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $campus = Campus::query()
            ->where('public_id', $data['campus_id'])
            ->where('status', 'active')
            ->whereIn('catalog_status', ['manual', 'listed'])
            ->firstOrFail();

        $fromCampusId = $user->campus_id;

        // 未变更（同校且未提交档案字段）→ 直接返回成功
        $profileKeys = ['grade_year', 'major', 'college'];
        $hasProfileInput = count(array_intersect($profileKeys, array_keys($data))) > 0;
        if ($fromCampusId === $campus->id && ! $hasProfileInput) {
            return ApiResponse::success($this->present($campus), 200, '学校已切换。');
        }

        if ($fromCampusId !== null && $fromCampusId !== $campus->id) {
            // 1. 未履约闲置订单检查
            $openOrders = SecondHandOrder::query()
                ->whereIn('status', ['pending', 'paid', 'shipped'])
                ->where(function ($q) use ($user): void {
                    $q->where('buyer_user_id', $user->id)->orWhere('seller_user_id', $user->id);
                })
                ->latest('id')
                ->limit(10)
                ->get(['public_id', 'status', 'created_at']);
            if ($openOrders->isNotEmpty()) {
                return ApiResponse::error(
                    'CAMPUS_CHANGE_BLOCKED_ORDERS',
                    '您有未完成的闲置订单，履约完成后才能更换学校。',
                    422,
                    [
                        'orders' => $openOrders->map(fn (SecondHandOrder $order) => [
                            'id' => $order->public_id,
                            'status' => $order->status,
                            'created_at' => $order->created_at?->toIso8601String(),
                        ])->values(),
                    ],
                );
            }

            // 2. 换校冷却校验（配置缺失时回退默认 30 天，不阻断换校流程）
            try {
                $cooldownDays = (int) ($this->settings->read('campus.change_cooldown_days')['value'] ?? 30);
            } catch (\Throwable) {
                $cooldownDays = 30;
            }
            $lastChange = CampusMembershipChange::query()
                ->where('user_id', $user->id)
                ->whereNull('changed_by_admin_id')
                ->where('reason', '!=', 'backfill_default')
                ->latest('created_at')
                ->first();
            if ($lastChange !== null && $cooldownDays > 0) {
                $nextAvailableAt = $lastChange->created_at->copy()->addDays($cooldownDays);
                if ($nextAvailableAt->isFuture()) {
                    return ApiResponse::error(
                        'CAMPUS_CHANGE_TOO_FREQUENT',
                        '换校过于频繁，请稍后再试。',
                        422,
                        ['next_available_at' => $nextAvailableAt->toIso8601String()],
                    );
                }
            }

            // 3. 写换校审计
            CampusMembershipChange::query()->create([
                'user_id' => $user->id,
                'from_campus_id' => $fromCampusId,
                'to_campus_id' => $campus->id,
                'reason' => 'self',
            ]);
        }

        // 4. 更新校区 + 档案字段（年级/专业/学院随账号保留，仅在显式提交时更新）
        $updates = ['campus_id' => $campus->id];
        foreach ($profileKeys as $key) {
            if (array_key_exists($key, $data)) {
                $updates[$key] = $data[$key];
            }
        }
        $user->update($updates);

        return ApiResponse::success(
            $this->present($campus) + ['require_restart' => $fromCampusId !== $campus->id],
            200,
            '学校已切换。',
        );
    }

    /**
     * @param  array<string,mixed>|null  $location
     * @return array<string, mixed>
     */
    public function present(Campus $campus, ?array $location = null, ?float $distanceKm = null): array
    {
        $location ??= $this->locations->resolve($campus);

        return [
            'id' => $campus->public_id,
            'code' => $campus->code,
            'name' => $campus->name,
            'province' => $campus->province,
            'city' => $campus->city,
            'district' => $location['district'] ?? $campus->district,
            'address' => $location['address'] ?? $campus->address,
            'emblem_url' => $this->branding->resolve($campus->emblem_asset_id, $campus->emblemAsset, $campus->emblem_url),
            'logo_url' => $this->branding->resolve($campus->logo_asset_id, $campus->logoAsset, $campus->logo_url),
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_accuracy' => $location['accuracy'],
            'location_name' => $location['name'] ?? $campus->name,
            'coordinate_system' => 'WGS84',
            'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
        ];
    }
}
