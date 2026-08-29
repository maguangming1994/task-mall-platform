<?php

namespace App\Modules\Campus\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campus\Models\Campus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
        ]);

        $items = Campus::query()
            ->where('status', 'active')
            ->when(isset($data['keyword']), function ($query) use ($data): void {
                $keyword = '%'.$data['keyword'].'%';
                $query->where(fn ($nested) => $nested
                    ->where('code', 'like', $keyword)
                    ->orWhere('name', 'like', $keyword)
                    ->orWhere('city', 'like', $keyword)
                    ->orWhere('district', 'like', $keyword));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Campus $campus) => $this->present(
                $campus,
                isset($data['latitude'], $data['longitude'])
                    ? $this->distance((float) $data['latitude'], (float) $data['longitude'], $campus)
                    : null,
            ))
            ->sortBy(fn (array $campus) => $campus['distance_km'] ?? PHP_FLOAT_MAX)
            ->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => ['required', Rule::exists('campuses', 'public_id')->where('status', 'active')],
        ]);
        /** @var User $user */
        $user = $request->user();
        $campus = Campus::query()->where('public_id', $data['campus_id'])->firstOrFail();
        $user->update(['campus_id' => $campus->id]);

        return ApiResponse::success($this->present($campus), 200, '学校已切换。');
    }

    /** @return array<string, mixed> */
    public function present(Campus $campus, ?float $distanceKm = null): array
    {
        return [
            'id' => $campus->public_id,
            'code' => $campus->code,
            'name' => $campus->name,
            'province' => $campus->province,
            'city' => $campus->city,
            'district' => $campus->district,
            'address' => $campus->address,
            'latitude' => $campus->latitude,
            'longitude' => $campus->longitude,
            'distance_km' => $distanceKm === null ? null : round($distanceKm, 1),
        ];
    }

    private function distance(float $latitude, float $longitude, Campus $campus): ?float
    {
        if ($campus->latitude === null || $campus->longitude === null) {
            return null;
        }

        $earthRadius = 6371;
        $latDelta = deg2rad($campus->latitude - $latitude);
        $lngDelta = deg2rad($campus->longitude - $longitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($campus->latitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
