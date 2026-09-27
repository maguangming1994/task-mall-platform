<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusLocationResolver;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class CampusController extends Controller
{
    public function __construct(private readonly CampusLocationResolver $locations) {}

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
                $location = $this->locations->resolve($campus);
                // City centroids help discover schools, but are not a campus location.
                // Never present the same city-centre distance as an exact school distance.
                $sortDistance = $nearby && $location['accuracy'] !== 'unavailable'
                    ? $this->distance(
                        (float) $data['latitude'],
                        (float) $data['longitude'],
                        $location['latitude'],
                        $location['longitude'],
                    )
                    : null;

                $distance = $location['accuracy'] === 'campus' ? $sortDistance : null;

                return compact('campus', 'location', 'distance', 'sortDistance');
            });

        if ($nearby) {
            $located = $located->sortBy(function (array $item): float {
                return match ($item['location']['accuracy']) {
                    'campus' => $item['distance'] ?? PHP_FLOAT_MAX,
                    'city' => $item['sortDistance'] ?? PHP_FLOAT_MAX / 2,
                    default => PHP_FLOAT_MAX,
                };
            });
        }

        return $located
            ->take($limit)
            ->map(fn (array $item) => $this->present($item['campus'], $item['location'], $item['distance']))
            ->values();
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => [
                'required',
                Rule::exists('campuses', 'public_id')
                    ->where('status', 'active')
                    ->whereIn('catalog_status', ['manual', 'listed']),
            ],
        ]);
        /** @var User $user */
        $user = $request->user();
        $campus = Campus::query()
            ->where('public_id', $data['campus_id'])
            ->where('status', 'active')
            ->whereIn('catalog_status', ['manual', 'listed'])
            ->firstOrFail();
        $user->update(['campus_id' => $campus->id]);

        return ApiResponse::success($this->present($campus), 200, '学校已切换。');
    }

    /**
     * @param  array{latitude:?float,longitude:?float,accuracy:'campus'|'city'|'unavailable'}|null  $location
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
            'district' => $campus->district,
            'address' => $campus->address,
            'emblem_url' => $this->brandUrl($campus->emblem_asset_id, $campus->emblemAsset, $campus->emblem_url),
            'logo_url' => $this->brandUrl($campus->logo_asset_id, $campus->logoAsset, $campus->logo_url),
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_accuracy' => $location['accuracy'],
            'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
        ];
    }

    private function brandUrl(?string $assetId, ?MediaAsset $asset, ?string $fallback): ?string
    {
        if (filled($assetId)) {
            return $asset?->status === 'active' && filled($asset->public_url)
                ? $asset->public_url
                : null;
        }

        return $fallback;
    }

    private function distance(float $latitude, float $longitude, float $targetLatitude, float $targetLongitude): float
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($targetLatitude - $latitude);
        $lngDelta = deg2rad($targetLongitude - $longitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($targetLatitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
