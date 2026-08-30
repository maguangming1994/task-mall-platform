<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Services\CampusLocationResolver;
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
                $distance = $nearby && $location['latitude'] !== null && $location['longitude'] !== null
                    ? $this->distance(
                        (float) $data['latitude'],
                        (float) $data['longitude'],
                        $location['latitude'],
                        $location['longitude'],
                    )
                    : null;

                return compact('campus', 'location', 'distance');
            });

        if ($nearby) {
            $located = $located->sortBy(fn (array $item) => $item['distance'] ?? PHP_FLOAT_MAX);
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
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_accuracy' => $location['accuracy'],
            'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
        ];
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
