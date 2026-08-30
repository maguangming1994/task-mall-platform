<?php

namespace Database\Seeders;

use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CampusSeeder extends Seeder
{
    public function run(): void
    {
        Campus::query()->firstOrCreate(
            ['code' => 'default-campus'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => '默认学校',
                'catalog_type' => 'manual',
                'catalog_status' => 'manual',
                'status' => 'active',
                'sort_order' => 100,
            ],
        );

        if (! app()->runningUnitTests()) {
            $this->call(CampusDirectorySeeder::class);
        }
    }
}
