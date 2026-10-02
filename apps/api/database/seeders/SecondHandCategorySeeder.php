<?php

namespace Database\Seeders;

use App\Modules\SecondHand\Models\SecondHandCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SecondHandCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => '数码电子', 'icon' => '📱', 'sort_order' => 1],
            ['name' => '教材书籍', 'icon' => '📚', 'sort_order' => 2],
            ['name' => '生活用品', 'icon' => '🏠', 'sort_order' => 3],
            ['name' => '服饰鞋包', 'icon' => '👕', 'sort_order' => 4],
            ['name' => '运动户外', 'icon' => '⚽', 'sort_order' => 5],
            ['name' => '美妆护肤', 'icon' => '💄', 'sort_order' => 6],
            ['name' => '乐器娱乐', 'icon' => '🎸', 'sort_order' => 7],
            ['name' => '其他', 'icon' => '📦', 'sort_order' => 99],
        ];

        foreach ($categories as $cat) {
            SecondHandCategory::query()->firstOrCreate(
                ['name' => $cat['name']],
                ['public_id' => (string) Str::ulid(), 'icon' => $cat['icon'], 'sort_order' => $cat['sort_order'], 'status' => 'active'],
            );
        }
    }
}
