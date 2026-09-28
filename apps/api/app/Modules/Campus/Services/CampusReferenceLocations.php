<?php

namespace App\Modules\Campus\Services;

use App\Modules\Campus\Models\Campus;
use RuntimeException;

class CampusReferenceLocations
{
    /** @var array<string, array<string,mixed>>|null */
    private ?array $schools = null;

    /** @return array<int, array{name:string,address:string,district:string,latitude:float,longitude:float}> */
    public function forCampus(Campus $campus): array
    {
        if (! filled($campus->official_school_code)) {
            return [];
        }
        $item = $this->schools()[$campus->official_school_code ?? ''] ?? null;
        if ($item === null || $item['name'] !== $campus->name || $item['province'] !== $campus->province
            || $item['city'] !== $campus->city || $campus->catalog_status === 'unlisted') {
            return [];
        }

        return $item['points'];
    }

    /** @return array<string,array<string,mixed>> */
    private function schools(): array
    {
        if ($this->schools !== null) {
            return $this->schools;
        }
        $contents = file_get_contents(database_path('data/campus_reference_locations.json'));
        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (($payload['metadata']['version'] ?? null) !== 1
            || ($payload['metadata']['coordinate_system'] ?? null) !== 'WGS84'
            || ! is_array($payload['items'] ?? null)
            || count($payload['items']) !== ($payload['metadata']['school_count'] ?? null)) {
            throw new RuntimeException('学校参考坐标数据结构无效。');
        }
        $schools = [];
        foreach ($payload['items'] as $item) {
            $code = $item['official_school_code'] ?? '';
            if (! is_string($code) || ! preg_match('/^\d{10}$/D', $code) || isset($schools[$code])
                || ! is_string($item['name'] ?? null) || ! is_string($item['province'] ?? null)
                || ! array_key_exists('city', $item) || ! is_array($item['points'] ?? null) || $item['points'] === []) {
                throw new RuntimeException('学校参考坐标包含无效或重复的学校。');
            }
            foreach ($item['points'] as $point) {
                if (! is_numeric($point['latitude'] ?? null) || $point['latitude'] < 18 || $point['latitude'] > 54
                    || ! is_numeric($point['longitude'] ?? null) || $point['longitude'] < 73 || $point['longitude'] > 135
                    || ! is_string($point['name'] ?? null) || ! is_string($point['address'] ?? null)
                    || ! is_string($point['district'] ?? null)) {
                    throw new RuntimeException('学校参考坐标包含无效校址。');
                }
            }
            $schools[$code] = $item;
        }

        return $this->schools = $schools;
    }
}
