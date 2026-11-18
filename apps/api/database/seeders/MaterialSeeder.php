<?php

namespace Database\Seeders;

use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => '考研资料', 'sort_order' => 10],
            ['name' => '考试资料', 'sort_order' => 20],
            ['name' => '专业课资料', 'sort_order' => 30],
            ['name' => '公共课资料', 'sort_order' => 40],
            ['name' => '实验报告', 'sort_order' => 50],
            ['name' => '其他资源', 'sort_order' => 60],
        ];

        foreach ($categories as $item) {
            MaterialCategory::query()->firstOrCreate(
                ['name' => $item['name']],
                $item + ['status' => 'active', 'public_id' => (string) Str::ulid()],
            );
        }

        // 演示资料仅用于本地/测试环境，生产环境不注入示例内容。
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $categoryIds = MaterialCategory::query()->pluck('id', 'name');
        $demos = [
            ['title' => '兽医执业', 'category' => '其他资源', 'netdisk_type' => 'baidu', 'share_url' => 'https://pan.baidu.com/s/1Hq3demo01', 'extract_code' => 'xk9d', 'file_type' => 'pdf'],
            ['title' => '2026江西专升本试卷大全', 'category' => '考试资料', 'netdisk_type' => 'baidu', 'share_url' => 'https://pan.baidu.com/s/1Hq3demo02', 'extract_code' => 'ab12', 'file_type' => 'word'],
            ['title' => '晓燕四级单词笔记', 'category' => '考试资料', 'netdisk_type' => 'baidu', 'share_url' => 'https://pan.baidu.com/s/1Hq3demo03', 'extract_code' => 'cd34', 'file_type' => 'pdf'],
            ['title' => '临床助理零基础课程', 'category' => '专业课资料', 'netdisk_type' => 'quark', 'share_url' => 'https://pan.quark.cn/s/demo04', 'extract_code' => 'ef56', 'file_type' => 'word'],
            ['title' => '猫科学习全套资料', 'category' => '专业课资料', 'netdisk_type' => 'baidu', 'share_url' => 'https://pan.baidu.com/s/1Hq3demo05', 'extract_code' => 'gh78', 'file_type' => 'pdf'],
            ['title' => '执业兽医全科手写干货笔记', 'category' => '专业课资料', 'netdisk_type' => 'aliyun', 'share_url' => 'https://www.alipan.com/s/demo06', 'extract_code' => 'ij90', 'file_type' => 'word'],
            ['title' => '800本宠物医学电子书', 'category' => '专业课资料', 'netdisk_type' => 'baidu', 'share_url' => 'https://pan.baidu.com/s/1Hq3demo07', 'extract_code' => 'kl12', 'file_type' => 'pdf'],
        ];

        foreach ($demos as $demo) {
            Material::query()->firstOrCreate(
                ['title' => $demo['title'], 'source' => 'admin'],
                [
                    'public_id' => (string) Str::ulid(),
                    'campus_id' => null,
                    'category_id' => $categoryIds[$demo['category']] ?? null,
                    'uploader_name' => '管理员',
                    'description' => '平台精选学习资料，仅供学习交流使用。',
                    'netdisk_type' => $demo['netdisk_type'],
                    'share_url' => $demo['share_url'],
                    'extract_code' => $demo['extract_code'],
                    'share_text' => $demo['title'].' '.$demo['share_url'].' 提取码：'.$demo['extract_code'],
                    'file_type' => $demo['file_type'],
                    'source' => 'admin',
                    'status' => 'approved',
                    'reviewed_at' => now(),
                ],
            );
        }
    }
}
