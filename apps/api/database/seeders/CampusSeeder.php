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
            ['public_id' => (string) Str::ulid(), 'name' => '默认学校', 'status' => 'active', 'sort_order' => 100],
        );
    }
}
