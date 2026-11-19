<?php

namespace Database\Seeders;

use App\Modules\Poster\Models\PosterTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PosterTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'code' => PosterTemplate::CODE_COMMUNITY_POST,
                'name' => '校园动态-默认蓝绿渐变',
                'description' => '社区帖子分享海报默认模板（日期未命中时使用）',
                'canvas_width' => 330,
                'canvas_height' => 520,
                'background_type' => PosterTemplate::BACKGROUND_GRADIENT,
                'background_value' => [
                    'stops' => ['#0e799d', '#19c6d1', '#76df63'],
                    'angle' => 135,
                ],
                'banner_config' => [
                    'label_text' => 'CAMPUS COMMUNITY',
                    'label_color' => '#10213a',
                    'label_bg' => '#ffdb4d',
                    'title_text' => '校园动态',
                    'title_color' => '#ffffff',
                ],
                'body_config' => [
                    'card_bg' => 'rgba(255,255,255,0.96)',
                    'category_color' => '#60798d',
                    'content_color' => '#173c58',
                ],
                'qrcode_config' => [
                    'cta_text' => '扫码查看动态详情',
                    'cta_color' => '#173c58',
                    'helper_text' => '分享给同学，一起发现校园',
                    'helper_color' => '#173c58',
                    'brand_watermark' => '任务商城 · 校园社区',
                    'brand_color' => '#748895',
                    'footer_text' => '任务商城 · 校园成长与奖励平台',
                    'footer_color' => '#ffffff',
                ],
                'date_rule' => null,
                'priority' => 0,
                'is_default' => true,
            ],
        ];

        foreach ($defaults as $item) {
            PosterTemplate::query()->updateOrCreate(
                ['code' => $item['code'], 'is_default' => true],
                [
                    ...$item,
                    'public_id' => (string) Str::ulid(),
                    'is_active' => true,
                ],
            );
        }
    }
}
