<?php

namespace App\Modules\Settings\Services;

class PageDecorationService
{
    public const SETTING_KEY = 'decoration.pages';

    public const PAGES = ['home', 'community', 'mall', 'account'];

    private const SECTION_TYPES = [
        'banner', 'announcement', 'quick_grid', 'image_banner', 'image_text',
        'product_grid', 'post_list', 'task_list', 'rich_text', 'spacer',
    ];

    public function __construct(private readonly SystemSettingService $settings) {}

    /** @return array{schema_version:int,pages:array<string,array{background_url:string,sections:array<int,array<string,mixed>>}>,version:int|null} */
    public function published(): array
    {
        $resolved = $this->settings->read(self::SETTING_KEY);

        return $this->normalize($resolved['value'], $resolved['version']);
    }

    /** @return array{schema_version:int,pages:array<string,array{background_url:string,sections:array<int,array<string,mixed>>}>} */
    public function emptyConfig(): array
    {
        $pages = [];
        foreach (self::PAGES as $page) {
            $pages[$page] = ['background_url' => '', 'sections' => []];
        }

        return ['schema_version' => 1, 'pages' => $pages];
    }

    /** @return array{schema_version:int,pages:array<string,array{background_url:string,sections:array<int,array<string,mixed>>}>,version:int|null} */
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
}
