<?php

namespace App\Modules\Campus\Services;

use App\Modules\Campus\Models\Campus;
use JsonException;
use RuntimeException;

class CampusLocationResolver
{
    private const CENTROIDS_FILE = 'china_city_centroids.json';

    /** @var array<string, array{latitude:float,longitude:float}>|null */
    private ?array $cities = null;

    /** @return array{latitude:?float,longitude:?float,accuracy:'campus'|'city'|'unavailable'} */
    public function resolve(Campus $campus): array
    {
        if ($campus->latitude !== null && $campus->longitude !== null) {
            return [
                'latitude' => (float) $campus->latitude,
                'longitude' => (float) $campus->longitude,
                'accuracy' => 'campus',
            ];
        }

        $city = is_string($campus->city) ? $campus->city : '';
        $coordinates = $city === '' ? null : ($this->cities()[$city] ?? null);
        if ($coordinates !== null) {
            return [
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
                'accuracy' => 'city',
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'accuracy' => 'unavailable',
        ];
    }

    /** @return array<string, array{latitude:float,longitude:float}> */
    private function cities(): array
    {
        if ($this->cities !== null) {
            return $this->cities;
        }

        $contents = @file_get_contents(database_path('data/'.self::CENTROIDS_FILE));
        if ($contents === false) {
            throw new RuntimeException('城市中心坐标数据文件不存在。');
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('城市中心坐标数据文件无法解析。', previous: $exception);
        }

        $metadata = is_array($payload) ? ($payload['metadata'] ?? null) : null;
        $cities = is_array($payload) ? ($payload['cities'] ?? null) : null;
        if (
            ! is_array($metadata)
            || ($metadata['coordinate_system'] ?? null) !== 'WGS84'
            || ! is_int($metadata['city_count'] ?? null)
            || ! is_array($cities)
            || $metadata['city_count'] !== count($cities)
        ) {
            throw new RuntimeException('城市中心坐标数据结构无效。');
        }

        $resolved = [];
        foreach ($cities as $name => $coordinates) {
            $latitude = is_array($coordinates) ? ($coordinates['latitude'] ?? null) : null;
            $longitude = is_array($coordinates) ? ($coordinates['longitude'] ?? null) : null;
            if (
                ! is_string($name) || $name === ''
                || ! is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90
                || ! is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180
            ) {
                throw new RuntimeException("城市 {$name} 的中心坐标无效。");
            }

            $resolved[$name] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $this->cities = $resolved;
    }
}
