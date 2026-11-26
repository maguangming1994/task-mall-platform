<?php

namespace App\Modules\Settings\Services;

use App\Common\Support\RichTextSanitizer;
use App\Modules\Settings\Models\SystemSettingDefinition;
use Illuminate\Validation\ValidationException;

class SystemSettingValidator
{
    public function __construct(private readonly RichTextSanitizer $richText) {}

    public function validate(SystemSettingDefinition $definition, mixed $value): mixed
    {
        $type = $definition->value_type;
        $validType = match ($type) {
            'boolean' => is_bool($value),
            'integer', 'money' => is_int($value),
            'decimal' => is_int($value) || is_float($value),
            'string', 'time', 'enum' => is_string($value),
            'multi_enum' => is_array($value) && array_is_list($value)
                && collect($value)->every(fn ($item) => is_string($item)),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };
        if (! $validType) {
            $this->fail($definition, "值类型必须为 {$type}。");
        }

        $rules = $definition->validation_rules ?? [];
        if (array_key_exists('const', $rules) && $value !== $rules['const']) {
            $this->fail($definition, '该平台级安全约束不允许修改。');
        }
        if (in_array($type, ['integer', 'money', 'decimal'], true)) {
            if (isset($rules['min']) && $value < $rules['min']) {
                $this->fail($definition, "不能小于 {$rules['min']}。");
            }
            if (isset($rules['max']) && $value > $rules['max']) {
                $this->fail($definition, "不能大于 {$rules['max']}。");
            }
        }
        if (in_array($type, ['string', 'time'], true)) {
            if (isset($rules['min_length']) && mb_strlen($value) < $rules['min_length']) {
                $this->fail($definition, "长度不能少于 {$rules['min_length']} 个字符。");
            }
            if (isset($rules['max_length']) && mb_strlen($value) > $rules['max_length']) {
                $this->fail($definition, "长度不能超过 {$rules['max_length']} 个字符。");
            }
            if ($type === 'time' && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
                $this->fail($definition, '时间格式必须为 HH:mm。');
            }
        }
        if ($type === 'enum' && ! in_array($value, $definition->options ?? [], true)) {
            $this->fail($definition, '值不在允许的选项中。');
        }
        if ($type === 'multi_enum') {
            $unknown = array_diff($value, $definition->options ?? []);
            if ($unknown !== []) {
                $this->fail($definition, '包含未开放的选项。');
            }
            $value = array_values(array_unique($value));
        }
        if ($type === 'object') {
            // Older admin clients created announcement payloads before icon_url
            // became configurable. Keep those drafts valid and normalize them to
            // the current schema instead of forcing an unrelated field error.
            if ($definition->setting_key === 'homepage.announcement' && ! array_key_exists('icon_url', $value)) {
                $value['icon_url'] = '';
            }
            foreach ($rules['required_keys'] ?? [] as $requiredKey) {
                if (! array_key_exists($requiredKey, $value)) {
                    $this->fail($definition, "缺少必填字段 {$requiredKey}。");
                }
            }
            foreach ($rules['properties'] ?? [] as $property => $propertyRules) {
                if (! array_key_exists($property, $value)) {
                    continue;
                }
                $propertyValue = $value[$property];
                if (($propertyRules['type'] ?? null) === 'array' && (! is_array($propertyValue) || ! array_is_list($propertyValue))) {
                    $this->fail($definition, "字段 {$property} 必须为数组。");
                }
                if (($propertyRules['type'] ?? null) === 'object' && (! is_array($propertyValue) || array_is_list($propertyValue))) {
                    $this->fail($definition, "字段 {$property} 必须为对象。");
                }
                if (($propertyRules['type'] ?? null) === 'integer' && ! is_int($propertyValue)) {
                    $this->fail($definition, "字段 {$property} 必须为整数。");
                }
                if (isset($propertyRules['min']) && $propertyValue < $propertyRules['min']) {
                    $this->fail($definition, "字段 {$property} 不能小于 {$propertyRules['min']}。");
                }
                if (isset($propertyRules['max']) && $propertyValue > $propertyRules['max']) {
                    $this->fail($definition, "字段 {$property} 不能大于 {$propertyRules['max']}。");
                }
            }

            if ($definition->setting_key === 'navigation.bottom_tabs') {
                $this->validateBottomNavigation($definition, $value);
            }
            if ($definition->setting_key === 'homepage.announcement') {
                $value['content'] = $this->richText->sanitize((string) ($value['content'] ?? ''));
                $plainText = $this->richText->toPlainText($value['content']);
                if ($plainText === '') {
                    $this->fail($definition, '公告内容不能为空。');
                }
                if (mb_strlen($plainText) > 2000) {
                    $this->fail($definition, '公告内容不能超过 2000 个文字。');
                }
            }
            if ($definition->setting_key === 'homepage.floating_support') {
                $this->validateFloatingSupport($definition, $value);
            }
            if ($definition->setting_key === 'decoration.pages') {
                $value = $this->validatePageDecoration($definition, $value);
            }
        }

        return $type === 'decimal' ? (float) $value : $value;
    }

    private function validateBottomNavigation(SystemSettingDefinition $definition, array $value): void
    {
        $items = $value['items'] ?? null;
        $style = $value['style'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) !== 5) {
            $this->fail($definition, '必须保留 5 个可排序导航槽位。');
        }
        if (! is_array($style) || array_is_list($style)) {
            $this->fail($definition, '样式配置必须为对象。');
        }

        $allowedTargets = ['home', 'tasks', 'products', 'community', 'account'];
        $ids = [];
        $targets = [];
        $enabledCount = 0;
        foreach ($items as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $this->fail($definition, '第 '.($index + 1).' 个导航项格式错误。');
            }
            foreach (['id', 'label', 'target', 'enabled', 'icon_url', 'active_icon_url'] as $key) {
                if (! array_key_exists($key, $item)) {
                    $this->fail($definition, '第 '.($index + 1)." 个导航项缺少字段 {$key}。");
                }
            }
            if (! is_string($item['id']) || ! preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $item['id'])) {
                $this->fail($definition, '导航项标识格式不正确。');
            }
            if (! is_string($item['label']) || mb_strlen(trim($item['label'])) < 1 || mb_strlen(trim($item['label'])) > 8) {
                $this->fail($definition, '导航文字长度必须为 1 至 8 个字符。');
            }
            if (! is_string($item['target']) || ! in_array($item['target'], $allowedTargets, true)) {
                $this->fail($definition, '导航跳转目标不在允许范围内。');
            }
            if (! is_bool($item['enabled'])) {
                $this->fail($definition, '导航显示状态必须为布尔值。');
            }
            foreach (['icon_url', 'active_icon_url'] as $urlKey) {
                if (! is_string($item[$urlKey]) || mb_strlen($item[$urlKey]) > 2048) {
                    $this->fail($definition, '导航图标地址格式不正确。');
                }
            }
            $ids[] = $item['id'];
            $targets[] = $item['target'];
            $enabledCount += $item['enabled'] ? 1 : 0;
        }
        if (count(array_unique($ids)) !== count($ids) || count(array_unique($targets)) !== count($targets)) {
            $this->fail($definition, '导航标识和跳转目标不能重复。');
        }
        if ($enabledCount < 3) {
            $this->fail($definition, '至少需要显示 3 个导航入口。');
        }

        foreach (['background_color', 'text_color', 'active_color', 'border_color'] as $colorKey) {
            if (! isset($style[$colorKey]) || ! is_string($style[$colorKey]) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $style[$colorKey])) {
                $this->fail($definition, "样式字段 {$colorKey} 必须为六位十六进制颜色。");
            }
        }
    }

    private function validateFloatingSupport(SystemSettingDefinition $definition, array $value): void
    {
        foreach (['enabled', 'show_label'] as $booleanKey) {
            if (! is_bool($value[$booleanKey] ?? null)) {
                $this->fail($definition, "字段 {$booleanKey} 必须为布尔值。");
            }
        }

        foreach (['icon_text', 'icon_url', 'label', 'subtitle', 'position'] as $stringKey) {
            if (! is_string($value[$stringKey] ?? null)) {
                $this->fail($definition, "字段 {$stringKey} 必须为文字。");
            }
        }

        foreach (['qr_code_url', 'qr_code_tip'] as $optionalStringKey) {
            if (array_key_exists($optionalStringKey, $value) && ! is_string($value[$optionalStringKey])) {
                $this->fail($definition, "字段 {$optionalStringKey} 必须为文字。");
            }
        }

        if (mb_strlen(trim($value['label'])) < 1 || mb_strlen(trim($value['label'])) > 8) {
            $this->fail($definition, '客服按钮名称长度必须为 1 至 8 个字符。');
        }
        if (mb_strlen(trim($value['subtitle'])) > 16 || mb_strlen(trim($value['icon_text'])) > 2) {
            $this->fail($definition, '客服副标题不能超过 16 个字符，图标文字不能超过 2 个字符。');
        }
        if (mb_strlen($value['icon_url']) > 2048 || mb_strlen((string) ($value['qr_code_url'] ?? '')) > 2048) {
            $this->fail($definition, '客服图标或二维码地址不能超过 2048 个字符。');
        }
        if (mb_strlen(trim((string) ($value['qr_code_tip'] ?? ''))) > 80) {
            $this->fail($definition, '二维码扫码说明不能超过 80 个字符。');
        }
        if (! in_array($value['position'], ['left', 'right'], true)) {
            $this->fail($definition, '客服悬浮位置只能选择左侧或右侧。');
        }
        if (! is_int($value['bottom_offset_rpx'] ?? null) || $value['bottom_offset_rpx'] < 130 || $value['bottom_offset_rpx'] > 400) {
            $this->fail($definition, '客服按钮底部距离必须为 130 至 400 rpx 的整数。');
        }
    }

    private function fail(SystemSettingDefinition $definition, string $message): never
    {
        throw ValidationException::withMessages(['value' => ["{$definition->name}：{$message}"]]);
    }

    private const DECORATION_PAGES = ['home', 'community', 'mall', 'account'];

    private const DECORATION_SECTION_TYPES = [
        'banner', 'announcement', 'quick_grid', 'image_banner', 'image_text',
        'product_grid', 'post_list', 'task_list', 'rich_text', 'spacer',
    ];

    private const DECORATION_LINK_TYPES = [
        'none', 'tasks', 'products', 'community', 'notifications', 'orders',
        'assets', 'push', 'second_hand', 'secondhand', 'material', 'express_pickup',
    ];

    private function validatePageDecoration(SystemSettingDefinition $definition, array $value): array
    {
        $schemaVersion = $value['schema_version'] ?? null;
        if (! is_int($schemaVersion) || $schemaVersion < 1) {
            $this->fail($definition, '装修配置版本号必须为正整数。');
        }
        $pages = $value['pages'] ?? null;
        if (! is_array($pages) || array_is_list($pages)) {
            $this->fail($definition, '装修页面配置必须为对象。');
        }

        $normalizedPages = [];
        foreach (self::DECORATION_PAGES as $pageKey) {
            $page = $pages[$pageKey] ?? null;
            if ($page === null) {
                $normalizedPages[$pageKey] = ['background_url' => '', 'sections' => []];
                continue;
            }
            if (! is_array($page) || array_is_list($page)) {
                $this->fail($definition, "页面 {$pageKey} 的配置必须为对象。");
            }
            $sections = $page['sections'] ?? [];
            if (! is_array($sections) || ! array_is_list($sections)) {
                $this->fail($definition, "页面 {$pageKey} 的区块必须为数组。");
            }
            if (count($sections) > 30) {
                $this->fail($definition, "页面 {$pageKey} 最多支持 30 个区块。");
            }

            $seenIds = [];
            $normalizedSections = [];
            foreach ($sections as $index => $section) {
                $normalizedSections[] = $this->normalizeDecorationSection(
                    $definition,
                    $section,
                    $seenIds,
                    "页面 {$pageKey} 的第 ".($index + 1).' 个区块',
                );
            }
            $normalizedPages[$pageKey] = [
                'background_url' => $this->normalizeDecorationUrl(
                    $definition,
                    (string) ($page['background_url'] ?? ''),
                    "页面 {$pageKey} 的背景图",
                ),
                'sections' => $normalizedSections,
            ];
        }

        foreach (array_keys($pages) as $unknownPage) {
            if (! in_array($unknownPage, self::DECORATION_PAGES, true)) {
                $this->fail($definition, "不支持装修页面 {$unknownPage}。");
            }
        }

        return ['schema_version' => $schemaVersion, 'pages' => $normalizedPages];
    }

    /** @param  array<string, bool>  $seenIds */
    private function normalizeDecorationSection(SystemSettingDefinition $definition, mixed $section, array &$seenIds, string $label): array
    {
        if (! is_array($section) || array_is_list($section)) {
            $this->fail($definition, "{$label} 格式错误。");
        }
        $id = $section['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $this->fail($definition, "{$label} 的标识格式不正确。");
        }
        if (isset($seenIds[$id])) {
            $this->fail($definition, "{$label} 的标识 {$id} 与其他区块重复。");
        }
        $seenIds[$id] = true;

        $type = $section['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::DECORATION_SECTION_TYPES, true)) {
            $this->fail($definition, "{$label} 的区块类型不受支持。");
        }
        $props = $section['props'] ?? [];
        if (! is_array($props) || array_is_list($props)) {
            $this->fail($definition, "{$label} 的属性必须为对象。");
        }

        return [
            'id' => $id,
            'type' => $type,
            'enabled' => is_bool($section['enabled'] ?? null) ? $section['enabled'] : true,
            'props' => $this->normalizeDecorationProps($definition, $type, $props, $label),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeDecorationProps(SystemSettingDefinition $definition, string $type, array $props, string $label): array
    {
        $string = fn (string $key, int $max, string $field) => $this->decorationString($definition, $props, $key, $max, "{$label} {$field}");
        $url = fn (string $key, string $field) => $this->normalizeDecorationUrl($definition, (string) ($props[$key] ?? ''), "{$label} {$field}");
        $link = fn (string $key) => $this->decorationLink($definition, $props, $key, "{$label} 跳转类型");
        $linkValue = fn () => $this->normalizeDecorationUrl($definition, (string) ($props['link_value'] ?? ''), "{$label} 跳转地址");
        $integer = fn (string $key, int $min, int $max, int $default) => $this->decorationInt($definition, $props, $key, $min, $max, $default, "{$label} {$key}");
        $boolean = fn (string $key, bool $default) => is_bool($props[$key] ?? null) ? $props[$key] : $default;

        return match ($type) {
            'banner' => (function () use ($definition, $props, $label, $url, $link, $linkValue, $integer, $boolean): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的轮播项必须为数组。");
                }
                if (count($rawItems) > 10) {
                    $this->fail($definition, "{$label} 最多支持 10 张轮播图。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 张轮播图格式错误。');
                    }
                    $items[] = [
                        'image_url' => $this->normalizeDecorationUrl($definition, (string) ($item['image_url'] ?? ''), "{$label} 第 ".($index + 1).' 张轮播图'),
                        'title' => $this->decorationString($definition, $item, 'title', 30, "{$label} 轮播标题"),
                        'subtitle' => $this->decorationString($definition, $item, 'subtitle', 60, "{$label} 轮播副标题"),
                        'button_text' => $this->decorationString($definition, $item, 'button_text', 12, "{$label} 轮播按钮"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 轮播跳转类型"),
                        'link_value' => $this->normalizeDecorationUrl($definition, (string) ($item['link_value'] ?? ''), "{$label} 轮播跳转地址"),
                    ];
                }

                return [
                    'items' => $items,
                    'autoplay' => $boolean('autoplay', true),
                    'interval_ms' => $integer('interval_ms', 1000, 15000, 4500),
                ];
            })(),
            'announcement' => [
                'icon_text' => $string('icon_text', 2, '图标文字'),
                'icon_url' => $url('icon_url', '图标'),
                'title' => $string('title', 30, '标题'),
                'content' => $this->normalizeDecorationRichText($definition, (string) ($props['content'] ?? ''), 500, "{$label} 公告内容"),
                'link_type' => $link('link_type'),
                'link_value' => $linkValue(),
            ],
            'quick_grid' => (function () use ($definition, $props, $label, $url, $link, $integer): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的入口必须为数组。");
                }
                if (count($rawItems) > 10) {
                    $this->fail($definition, "{$label} 最多支持 10 个入口。");
                }
                $items = [];
                $seenIds = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个入口格式错误。');
                    }
                    $itemId = (string) ($item['id'] ?? '');
                    if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $itemId) || isset($seenIds[$itemId])) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个入口标识不正确或重复。');
                    }
                    $seenIds[$itemId] = true;
                    $items[] = [
                        'id' => $itemId,
                        'label' => $this->decorationString($definition, $item, 'label', 8, "{$label} 入口名称"),
                        'mark' => $this->decorationString($definition, $item, 'mark', 2, "{$label} 入口文字图标"),
                        'icon_url' => $this->normalizeDecorationUrl($definition, (string) ($item['icon_url'] ?? ''), "{$label} 入口图标"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 入口跳转类型"),
                        'enabled' => is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
                    ];
                }

                return [
                    'columns' => $integer('columns', 3, 5, 4),
                    'items' => $items,
                ];
            })(),
            'image_banner' => [
                'image_url' => $url('image_url', '图片'),
                'link_type' => $link('link_type'),
                'link_value' => $linkValue(),
                'height_rpx' => $integer('height_rpx', 80, 600, 200),
            ],
            'image_text' => [
                'layout' => in_array($props['layout'] ?? '', ['left', 'right'], true) ? $props['layout'] : 'left',
                'image_url' => $url('image_url', '图片'),
                'title' => $string('title', 30, '标题'),
                'description' => $string('description', 120, '描述'),
                'link_type' => $link('link_type'),
                'link_value' => $linkValue(),
            ],
            'product_grid' => [
                'title' => $string('title', 20, '标题'),
                'subtitle' => $string('subtitle', 40, '副标题'),
                'limit' => $integer('limit', 1, 20, 6),
                'category_id' => $string('category_id', 64, '分类标识'),
                'price_mode' => in_array($props['price_mode'] ?? '', ['default', 'points', 'cash', 'cash_points'], true)
                    ? $props['price_mode']
                    : 'default',
            ],
            'post_list' => [
                'title' => $string('title', 20, '标题'),
                'subtitle' => $string('subtitle', 40, '副标题'),
                'limit' => $integer('limit', 1, 20, 6),
                'category_code' => $string('category_code', 32, '分类标识'),
            ],
            'task_list' => [
                'title' => $string('title', 20, '标题'),
                'subtitle' => $string('subtitle', 40, '副标题'),
                'limit' => $integer('limit', 1, 10, 4),
                'category' => $string('category', 32, '任务分类'),
            ],
            'rich_text' => [
                'html' => $this->normalizeDecorationRichText($definition, (string) ($props['html'] ?? ''), 5000, "{$label} 富文本"),
            ],
            'spacer' => [
                'height_rpx' => $integer('height_rpx', 0, 200, 24),
            ],
        };
    }

    private function decorationString(SystemSettingDefinition $definition, array $source, string $key, int $max, string $label): string
    {
        $value = $source[$key] ?? '';
        if (! is_string($value)) {
            $this->fail($definition, "{$label} 必须为文字。");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $this->fail($definition, "{$label} 不能超过 {$max} 个字符。");
        }

        return $value;
    }

    private function decorationInt(SystemSettingDefinition $definition, array $props, string $key, int $min, int $max, int $default, string $label): int
    {
        if (! array_key_exists($key, $props)) {
            return $default;
        }
        $value = $props[$key];
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->fail($definition, "{$label} 必须为 {$min} 至 {$max} 的整数。");
        }

        return $value;
    }

    private function decorationLink(SystemSettingDefinition $definition, array $props, string $key, string $label): string
    {
        $value = $props[$key] ?? 'none';
        if (! is_string($value)) {
            $this->fail($definition, "{$label} 必须为文字。");
        }
        if (! in_array($value, self::DECORATION_LINK_TYPES, true) && ! str_starts_with($value, 'tasks:')) {
            $this->fail($definition, "{$label} 不在允许的跳转范围内。");
        }

        return $value;
    }

    private function normalizeDecorationUrl(SystemSettingDefinition $definition, string $url, string $label): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (mb_strlen($url) > 2048) {
            $this->fail($definition, "{$label} 地址不能超过 2048 个字符。");
        }
        if (! preg_match('#^(https?://|/)#i', $url)) {
            $this->fail($definition, "{$label} 只允许 https://、http:// 或站内相对路径。");
        }

        return $url;
    }

    private function normalizeDecorationRichText(SystemSettingDefinition $definition, string $html, int $maxPlain, string $label): string
    {
        $html = $this->richText->sanitize($html);
        if (mb_strlen($this->richText->toPlainText($html)) > $maxPlain) {
            $this->fail($definition, "{$label} 不能超过 {$maxPlain} 个文字。");
        }

        return $html;
    }
}
