<?php

namespace App\Modules\Settings\Services;

class PageDecorationService
{
    public const SETTING_KEY = 'decoration.pages';

    public const PAGES = ['home', 'community', 'mall', 'account'];

    private const SECTION_TYPES = [
        'banner', 'announcement', 'quick_grid', 'image_banner', 'image_text',
        'product_grid', 'post_list', 'task_list', 'rich_text', 'spacer',
        'nav_bar', 'search_box', 'category_nav', 'coupon', 'floating_box',
        'page_title', 'list_nav', 'tag_group', 'image_group', 'button_group', 'divider',
        'activity_group', 'member_card', 'cart_entry', 'qr_code',
        'location_search', 'order_marquee', 'video', 'tab_nav', 'ad_banner',
        'image_showcase', 'notice_bar', 'hot_tags', 'title_bar', 'tile_ad', 'popup',
        // 首页核心区块（对齐小程序线上首页 home-v13 布局）
        'brand_card', 'point_card', 'recommendation_card', 'product_section', 'trust_card',
    ];

    public function __construct(private readonly SystemSettingService $settings) {}

    /** @return array{schema_version:int,pages:array<string,array<string,mixed>>,version:int|null} */
    public function published(): array
    {
        $resolved = $this->settings->read(self::SETTING_KEY);

        return $this->normalize($resolved['value'], $resolved['version']);
    }

    /** @return array{schema_version:int,pages:array<string,array<string,mixed>>} */
    public function emptyConfig(): array
    {
        $pages = [];
        foreach (self::PAGES as $page) {
            $pages[$page] = $this->emptyPage();
        }
        // 首页默认底稿：把小程序线上 home-v13 现有区块映射为装修组件，
        // 让后台打开即看到"小程序现在长什么样"，可在原有基础上增删改。
        $pages['home']['sections'] = $this->homeBlueprintSections();

        return ['schema_version' => 1, 'pages' => $pages];
    }

    /**
     * 首页装修默认底稿（对齐小程序首页 home-v13 的现有区块顺序与文案）。
     * 仅用于空配置/新建方案时的初始值，不改变线上已发布的真实配置。
     *
     * @return array<int,array<string,mixed>>
     */
    private function homeBlueprintSections(): array
    {
        $make = static function (string $type, array $props): array {
            return [
                'id' => 'default-'.substr(md5($type.json_encode($props)), 0, 12),
                'type' => $type,
                'enabled' => true,
                'style' => [
                    'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                    'padding' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                    'radius' => 0,
                    'corner_radius' => false,
                    'radius_corners' => ['top_left' => 0, 'top_right' => 0, 'bottom_left' => 0, 'bottom_right' => 0],
                    'opacity' => 100,
                    'display_daily_start' => '',
                    'display_daily_end' => '',
                    'background_type' => 'none',
                    'background_color' => '',
                    'background_image' => '',
                    'background_gradient' => '',
                    'border' => false,
                    'border_color' => '#E7EBF2',
                    'shadow' => false,
                    'channels' => ['h5', 'miniapp'],
                    'display_start' => '',
                    'display_end' => '',
                ],
                'props' => $props,
            ];
        };

        return [
            $make('brand_card', [
                'logo_text' => 'TM', 'logo_url' => '', 'name' => '任务商城',
                'caption' => 'TASK · REWARD · LIFE', 'link_type' => 'none',
            ]),
            $make('point_card', [
                'points_label' => '我的积分', 'sign_button_text' => '每日签到',
                'link_type' => 'assets',
            ]),
            $make('banner', [
                'items' => [[
                    'image_url' => '', 'title' => '三种购买方式，价格各端一致',
                    'subtitle' => '积分兑换、现金购买或现金加积分，按商品配置自由选择。',
                    'button_text' => '去积分商城', 'link_type' => 'products', 'link_value' => '',
                ]],
                'autoplay' => true, 'interval_ms' => 4500, 'duration_ms' => 300,
                'height_rpx' => 280, 'radius_rpx' => 24, 'indicator' => 'dots',
                'indicator_position' => 'bottom-right', 'show_title' => true,
            ]),
            $make('announcement', [
                'icon_text' => '公', 'icon_url' => '', 'title' => '平台公告',
                'content' => '欢迎来到任务商城，完成任务即可领取奖励。',
                'link_type' => 'notifications', 'link_value' => '', 'scroll' => true,
                'speed' => 3, 'title_size_rpx' => 28, 'text_color' => '#8A6A1F',
                'icon_size_rpx' => 48, 'show_arrow' => true,
            ]),
            $make('recommendation_card', [
                'section_title' => '今日推荐', 'section_subtitle' => '从一件简单的小事开始',
                'title' => '完成新手任务', 'description' => '完善资料、熟悉流程，开启你的第一份奖励',
                'tag' => '简单', 'reward_text' => '最高 +300 积分', 'time_text' => '约 3 分钟',
                'icon_text' => '新', 'icon_url' => '', 'background_url' => '', 'link_type' => 'tasks',
            ]),
            $make('quick_grid', [
                'columns' => 4, 'icon_shape' => 'rounded', 'icon_size_rpx' => 84,
                'label_size_rpx' => 24, 'label_color' => '#3C4961', 'row_gap_rpx' => 20,
                'data_source' => 'manual', 'limit' => 8,
                'items' => [
                    ['id' => 'cat-errand', 'label' => '跑腿代办', 'note' => '取件带饭', 'mark' => '跑', 'tone' => 'green', 'icon_url' => '', 'link_type' => 'tasks:errand', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-study', 'label' => '学习助力', 'note' => '答疑打印', 'mark' => '学', 'tone' => 'blue', 'icon_url' => '', 'link_type' => 'tasks:study', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-secondhand', 'label' => '二手闲置', 'note' => '淘好物', 'mark' => '闲', 'tone' => 'orange', 'icon_url' => '', 'link_type' => 'secondhand', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-express', 'label' => '快递代取', 'note' => '代取包裹', 'mark' => '取', 'tone' => 'cyan', 'icon_url' => '', 'link_type' => 'express_pickup', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-skill', 'label' => '技能服务', 'note' => '修电脑修车', 'mark' => '技', 'tone' => 'violet', 'icon_url' => '', 'link_type' => 'tasks:skill', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-lost', 'label' => '失物招领', 'note' => '寻物启事', 'mark' => '失', 'tone' => 'pink', 'icon_url' => '', 'link_type' => 'tasks:lost_found', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'cat-teamup', 'label' => '组队拼单', 'note' => '搭子拼团', 'mark' => '队', 'tone' => 'cyan', 'icon_url' => '', 'link_type' => 'tasks:teamup', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                    ['id' => 'pt-center', 'label' => '地推中心', 'note' => '校园推广', 'mark' => '推', 'tone' => 'green', 'icon_url' => '', 'link_type' => 'push', 'enabled' => true, 'badge_type' => 'none', 'badge_text' => '', 'badge_image' => ''],
                ],
            ]),
            $make('task_list', [
                'title' => '附近', 'subtitle' => '', 'limit' => 10, 'category' => '',
            ]),
            $make('product_section', [
                'section_title' => '精选兑换', 'section_subtitle' => '三种购买方式，价格各端一致',
                'cards' => [
                    ['id' => 'points-choice', 'eyebrow' => '积分好物', 'title' => '日常精选', 'price_text' => '积分兑换', 'tone' => 'blue', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
                    ['id' => 'life-choice', 'eyebrow' => '限时推荐', 'title' => '生活补给', 'price_text' => '现金 / 积分', 'tone' => 'warm', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
                ],
            ]),
            $make('trust_card', [
                'title' => '每一笔资产都有记录',
                'description' => '积分来源、有效期、退款和余额变动清晰可查',
                'icon_url' => '', 'background_url' => '', 'link_type' => 'assets',
            ]),
            $make('floating_box', [
                'image_url' => '', 'icon_text' => '服', 'label' => '客服',
                'link_type' => 'none', 'bottom_rpx' => 180,
            ]),
        ];
    }

    /** @return array{schema_version:int,pages:array<string,array<string,mixed>>,version:int|null} */
    private function normalize(mixed $value, ?int $version): array
    {
        $config = $this->emptyConfig();
        if (! is_array($value) || array_is_list($value)) {
            return [...$config, 'version' => $version];
        }
        if (is_int($value['schema_version'] ?? null) && $value['schema_version'] > 0) {
            $config['schema_version'] = $value['schema_version'];
        }
        $pages = is_array($value['pages'] ?? null) && ! array_is_list($value['pages']) ? $value['pages'] : [];
        foreach (self::PAGES as $page) {
            $pageValue = $pages[$page] ?? null;
            if (! is_array($pageValue) || array_is_list($pageValue)) {
                continue;
            }
            $sections = is_array($pageValue['sections'] ?? null) && array_is_list($pageValue['sections'])
                ? $pageValue['sections']
                : [];
            $config['pages'][$page] = [
                'name' => is_string($pageValue['name'] ?? null) ? $pageValue['name'] : '',
                'title' => is_string($pageValue['title'] ?? null) ? $pageValue['title'] : '',
                'description' => is_string($pageValue['description'] ?? null) ? $pageValue['description'] : '',
                'cover_url' => is_string($pageValue['cover_url'] ?? null) ? $pageValue['cover_url'] : '',
                'background_color' => is_string($pageValue['background_color'] ?? null) ? $pageValue['background_color'] : '',
                'background_url' => is_string($pageValue['background_url'] ?? null) ? $pageValue['background_url'] : '',
                'sections' => array_values(array_filter(
                    $sections,
                    fn ($section) => is_array($section)
                        && ! array_is_list($section)
                        && is_string($section['type'] ?? null)
                        && in_array($section['type'], self::SECTION_TYPES, true)
                        && is_array($section['props'] ?? null)
                        && ! array_is_list($section['props']),
                )),
            ];
        }

        return [...$config, 'version' => $version];
    }

    /** @return array<string, mixed> */
    private function emptyPage(): array
    {
        return [
            'name' => '',
            'title' => '',
            'description' => '',
            'cover_url' => '',
            'background_color' => '',
            'background_url' => '',
            'sections' => [],
        ];
    }
}
