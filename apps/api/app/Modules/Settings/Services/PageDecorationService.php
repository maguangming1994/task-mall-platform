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

        return ['schema_version' => 1, 'pages' => $pages];
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
