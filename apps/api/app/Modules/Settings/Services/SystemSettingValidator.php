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
            if ($definition->setting_key === 'decoration.schemes') {
                $value = $this->validateDecorationSchemes($definition, $value);
            }
            if ($definition->setting_key === 'decoration.receipt_templates') {
                $value = $this->validateReceiptTemplates($definition, $value);
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
        'nav_bar', 'search_box', 'category_nav', 'coupon', 'floating_box',
        'page_title', 'list_nav', 'tag_group', 'image_group', 'button_group', 'divider',
        'activity_group', 'member_card', 'cart_entry', 'qr_code',
        'location_search', 'order_marquee', 'video', 'tab_nav', 'ad_banner',
        'image_showcase', 'notice_bar', 'hot_tags', 'title_bar', 'tile_ad', 'popup',
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
                $normalizedPages[$pageKey] = $this->decorationPageDefaults();
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
                'name' => $this->decorationString($definition, $page, 'name', 30, "页面 {$pageKey} 的页面名称"),
                'title' => $this->decorationString($definition, $page, 'title', 40, "页面 {$pageKey} 的页面标题"),
                'description' => $this->decorationString($definition, $page, 'description', 200, "页面 {$pageKey} 的页面介绍"),
                'cover_url' => $this->normalizeDecorationUrl($definition, (string) ($page['cover_url'] ?? ''), "页面 {$pageKey} 的封面图"),
                'background_color' => $this->normalizeDecorationColor($definition, (string) ($page['background_color'] ?? ''), "页面 {$pageKey} 的背景色"),
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

    private const DECORATION_CHANNELS = ['h5', 'miniapp'];

    private const RECEIPT_BLOCK_TYPES = [
        'text', 'product_header', 'product_item', 'divider', 'blank', 'qr_code', 'distribution_code',
    ];

    private const RECEIPT_PRINT_TYPES = ['express_pickup', 'delivery', 'pickup', 'dine_in', 'wechat_reserve'];

    private const RECEIPT_BINDING_SLOTS = ['receipt', 'split', 'label'];

    /** @return array<string, mixed> */
    private function validateDecorationSchemes(SystemSettingDefinition $definition, array $value): array
    {
        $schemaVersion = $value['schema_version'] ?? null;
        if (! is_int($schemaVersion) || $schemaVersion < 1) {
            $this->fail($definition, '方案配置版本号必须为正整数。');
        }
        $pages = $value['pages'] ?? null;
        if (! is_array($pages) || array_is_list($pages)) {
            $this->fail($definition, '方案生效状态必须为对象。');
        }
        $active = [];
        foreach (self::DECORATION_PAGES as $pageKey) {
            $channels = $pages[$pageKey] ?? [];
            if (! is_array($channels) || array_is_list($channels)) {
                $channels = [];
            }
            $active[$pageKey] = [];
            foreach (self::DECORATION_CHANNELS as $channel) {
                $pointer = $channels[$channel] ?? null;
                $active[$pageKey][$channel] = is_string($pointer) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pointer)
                    ? $pointer
                    : null;
            }
        }
        foreach (array_keys($pages) as $unknownPage) {
            if (! in_array($unknownPage, self::DECORATION_PAGES, true)) {
                $this->fail($definition, "不支持为页面 {$unknownPage} 保存方案。");
            }
        }

        $schemes = $value['schemes'] ?? [];
        if (! is_array($schemes) || ! array_is_list($schemes)) {
            $this->fail($definition, '页面方案列表必须为数组。');
        }
        if (count($schemes) > 60) {
            $this->fail($definition, '最多保存 60 个页面方案。');
        }

        $seenIds = [];
        $normalized = [];
        foreach ($schemes as $index => $scheme) {
            $label = '第 '.($index + 1).' 个页面方案';
            if (! is_array($scheme) || array_is_list($scheme)) {
                $this->fail($definition, "{$label}格式错误。");
            }
            $id = $scheme['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $this->fail($definition, "{$label}的标识格式不正确。");
            }
            if (isset($seenIds[$id])) {
                $this->fail($definition, "{$label}的标识 {$id} 与其他方案重复。");
            }
            $seenIds[$id] = true;

            $pageKey = $scheme['page_key'] ?? null;
            if (! is_string($pageKey) || ! in_array($pageKey, self::DECORATION_PAGES, true)) {
                $this->fail($definition, "{$label}的页面类型不受支持。");
            }
            $name = $this->decorationString($definition, $scheme, 'name', 40, "{$label}的名称");
            if ($name === '') {
                $this->fail($definition, "{$label}的名称不能为空。");
            }
            $page = $scheme['page'] ?? null;
            if (! is_array($page) || array_is_list($page)) {
                $this->fail($definition, "{$label}的页面配置必须为对象。");
            }
            $sections = $page['sections'] ?? [];
            if (! is_array($sections) || ! array_is_list($sections)) {
                $this->fail($definition, "{$label}的页面区块必须为数组。");
            }
            if (count($sections) > 30) {
                $this->fail($definition, "{$label}最多支持 30 个区块。");
            }
            $seenSectionIds = [];
            $normalizedSections = [];
            foreach ($sections as $sectionIndex => $section) {
                $normalizedSections[] = $this->normalizeDecorationSection(
                    $definition,
                    $section,
                    $seenSectionIds,
                    "{$label}的第 ".($sectionIndex + 1).' 个区块',
                );
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'page_key' => $pageKey,
                'page' => [
                    'name' => $this->decorationString($definition, $page, 'name', 30, "{$label}的页面名称"),
                    'title' => $this->decorationString($definition, $page, 'title', 40, "{$label}的页面标题"),
                    'description' => $this->decorationString($definition, $page, 'description', 200, "{$label}的页面介绍"),
                    'cover_url' => $this->normalizeDecorationUrl($definition, (string) ($page['cover_url'] ?? ''), "{$label}的封面图"),
                    'background_color' => $this->normalizeDecorationColor($definition, (string) ($page['background_color'] ?? ''), "{$label}的背景色"),
                    'background_url' => $this->normalizeDecorationUrl($definition, (string) ($page['background_url'] ?? ''), "{$label}的背景图"),
                    'sections' => $normalizedSections,
                ],
                'created_at' => is_string($scheme['created_at'] ?? null) ? mb_substr($scheme['created_at'], 0, 40) : '',
                'updated_at' => is_string($scheme['updated_at'] ?? null) ? mb_substr($scheme['updated_at'], 0, 40) : '',
            ];
        }

        // 生效指针必须指向同页面的已有方案，避免脏指针把线上页面刷成空配置。
        foreach ($active as $pageKey => $channels) {
            foreach ($channels as $channel => $pointer) {
                if ($pointer === null) {
                    continue;
                }
                $exists = false;
                foreach ($normalized as $scheme) {
                    if ($scheme['id'] === $pointer && $scheme['page_key'] === $pageKey) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $active[$pageKey][$channel] = null;
                }
            }
        }

        return ['schema_version' => $schemaVersion, 'pages' => $active, 'schemes' => $normalized];
    }

    /** @return array<string, mixed> */
    private function validateReceiptTemplates(SystemSettingDefinition $definition, array $value): array
    {
        $schemaVersion = $value['schema_version'] ?? null;
        if (! is_int($schemaVersion) || $schemaVersion < 1) {
            $this->fail($definition, '小票模板配置版本号必须为正整数。');
        }
        $templates = $value['templates'] ?? [];
        if (! is_array($templates) || ! array_is_list($templates)) {
            $this->fail($definition, '小票模板列表必须为数组。');
        }
        if (count($templates) > 60) {
            $this->fail($definition, '最多保存 60 个小票模板。');
        }

        $seenIds = [];
        $normalized = [];
        foreach ($templates as $index => $template) {
            $label = '第 '.($index + 1).' 个小票模板';
            if (! is_array($template) || array_is_list($template)) {
                $this->fail($definition, "{$label}格式错误。");
            }
            $id = $template['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $this->fail($definition, "{$label}的标识格式不正确。");
            }
            if (isset($seenIds[$id])) {
                $this->fail($definition, "{$label}的标识 {$id} 与其他模板重复。");
            }
            $seenIds[$id] = true;

            $name = $this->decorationString($definition, $template, 'name', 40, "{$label}的名称");
            if ($name === '') {
                $this->fail($definition, "{$label}的名称不能为空。");
            }
            $campusId = $template['campus_id'] ?? null;
            if ($campusId !== null && (! is_int($campusId) || $campusId < 1)) {
                $this->fail($definition, "{$label}的所属区域不正确。");
            }
            $paperWidth = $template['paper_width'] ?? 58;
            if (! in_array($paperWidth, [58, 80], true)) {
                $this->fail($definition, "{$label}的模板类型只能为 58mm 或 80mm。");
            }
            $printType = $template['print_type'] ?? null;
            if (! is_string($printType) || ! in_array($printType, self::RECEIPT_PRINT_TYPES, true)) {
                $this->fail($definition, "{$label}的打印场景不受支持。");
            }
            $status = $template['status'] ?? 'disabled';
            if (! in_array($status, ['enabled', 'disabled'], true)) {
                $this->fail($definition, "{$label}的状态不正确。");
            }

            $blocks = $template['blocks'] ?? [];
            if (! is_array($blocks) || ! array_is_list($blocks)) {
                $this->fail($definition, "{$label}的组件必须为数组。");
            }
            if (count($blocks) > 40) {
                $this->fail($definition, "{$label}最多支持 40 个组件。");
            }
            $seenBlockIds = [];
            $normalizedBlocks = [];
            foreach ($blocks as $blockIndex => $block) {
                $blockLabel = "{$label}的第 ".($blockIndex + 1).' 个组件';
                if (! is_array($block) || array_is_list($block)) {
                    $this->fail($definition, "{$blockLabel}格式错误。");
                }
                $blockId = $block['id'] ?? null;
                if (! is_string($blockId) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $blockId)) {
                    $this->fail($definition, "{$blockLabel}的标识格式不正确。");
                }
                if (isset($seenBlockIds[$blockId])) {
                    $this->fail($definition, "{$blockLabel}的标识 {$blockId} 与其他组件重复。");
                }
                $seenBlockIds[$blockId] = true;
                $type = $block['type'] ?? null;
                if (! is_string($type) || ! in_array($type, self::RECEIPT_BLOCK_TYPES, true)) {
                    $this->fail($definition, "{$blockLabel}的类型不受支持。");
                }
                $props = $block['props'] ?? [];
                if (! is_array($props) || array_is_list($props)) {
                    $this->fail($definition, "{$blockLabel}的属性必须为对象。");
                }
                $content = $props['content'] ?? '';
                if (! is_string($content)) {
                    $this->fail($definition, "{$blockLabel}的内容必须为文字。");
                }
                $align = $props['align'] ?? 'left';
                if (! in_array($align, ['left', 'center', 'right'], true)) {
                    $this->fail($definition, "{$blockLabel}的对齐方式不正确。");
                }
                $fontSize = $props['font_size'] ?? 0;
                if (! is_int($fontSize) || $fontSize < 0 || $fontSize > 2) {
                    $this->fail($definition, "{$blockLabel}的字号必须为 0 至 2。");
                }
                $bold = $props['bold'] ?? 0;
                if (! is_int($bold) || $bold < 0 || $bold > 2) {
                    $this->fail($definition, "{$blockLabel}的加粗必须为 0 至 2。");
                }
                $normalizedBlocks[] = [
                    'id' => $blockId,
                    'type' => $type,
                    'props' => [
                        'content' => mb_substr($content, 0, 500),
                        'align' => $align,
                        'font_size' => $fontSize,
                        'bold' => $bold,
                    ],
                ];
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'campus_id' => $campusId,
                'paper_width' => $paperWidth,
                'print_type' => $printType,
                'status' => $status,
                'blocks' => $normalizedBlocks,
                'created_at' => is_string($template['created_at'] ?? null) ? mb_substr($template['created_at'], 0, 40) : '',
                'updated_at' => is_string($template['updated_at'] ?? null) ? mb_substr($template['updated_at'], 0, 40) : '',
            ];
        }

        $bindings = $value['bindings'] ?? [];
        if (! is_array($bindings) || array_is_list($bindings)) {
            $this->fail($definition, '打印模板绑定必须为对象。');
        }
        $normalizedBindings = [];
        foreach (self::RECEIPT_PRINT_TYPES as $scene) {
            $sceneValue = $bindings[$scene] ?? [];
            if (! is_array($sceneValue) || array_is_list($sceneValue)) {
                $sceneValue = [];
            }
            $normalizedBindings[$scene] = [];
            foreach (self::RECEIPT_BINDING_SLOTS as $slot) {
                $templateId = $sceneValue[$slot] ?? null;
                $valid = is_string($templateId) && isset($seenIds[$templateId]);
                $normalizedBindings[$scene][$slot] = $valid ? $templateId : null;
            }
        }

        return ['schema_version' => $schemaVersion, 'templates' => $normalized, 'bindings' => $normalizedBindings];
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
            'style' => $this->normalizeDecorationStyle($definition, $section['style'] ?? [], $label),
            'props' => $this->normalizeDecorationProps($definition, $type, $props, $label),
        ];
    }

    /** @return array<string, mixed> */
    private function decorationPageDefaults(): array
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

    /** @return array<string, mixed> */
    private function normalizeDecorationStyle(SystemSettingDefinition $definition, mixed $style, string $label): array
    {
        if (! is_array($style) || array_is_list($style)) {
            $style = [];
        }
        $spacing = function (string $key) use ($style, $definition, $label): array {
            $source = is_array($style[$key] ?? null) && ! array_is_list($style[$key]) ? $style[$key] : [];
            $normalized = [];
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $value = $source[$side] ?? 0;
                if (! is_int($value) || $value < 0 || $value > 100) {
                    $this->fail($definition, "{$label} 的{$key}必须为 0 至 100 的整数。");
                }
                $normalized[$side] = $value;
            }

            return $normalized;
        };

        $backgroundType = $style['background_type'] ?? 'none';
        if (! in_array($backgroundType, ['none', 'color', 'image', 'gradient'], true)) {
            $this->fail($definition, "{$label} 的背景类型不受支持。");
        }
        $radius = $style['radius'] ?? 0;
        if (! is_int($radius) || $radius < 0 || $radius > 100) {
            $this->fail($definition, "{$label} 的圆角必须为 0 至 100 的整数。");
        }
        $cornerRadius = is_bool($style['corner_radius'] ?? null) ? $style['corner_radius'] : false;
        $cornerSource = is_array($style['radius_corners'] ?? null) && ! array_is_list($style['radius_corners']) ? $style['radius_corners'] : [];
        $corners = [];
        foreach (['top_left', 'top_right', 'bottom_left', 'bottom_right'] as $cornerKey) {
            $cornerValue = $cornerSource[$cornerKey] ?? 0;
            if (! is_int($cornerValue) || $cornerValue < 0 || $cornerValue > 100) {
                $this->fail($definition, "{$label} 的单角圆角必须为 0 至 100 的整数。");
            }
            $corners[$cornerKey] = $cornerValue;
        }
        $opacity = $style['opacity'] ?? 100;
        if (! is_int($opacity) || $opacity < 20 || $opacity > 100) {
            $this->fail($definition, "{$label} 的透明度必须为 20 至 100 的整数。");
        }
        $channels = $style['channels'] ?? ['h5', 'miniapp'];
        if (! is_array($channels) || ! array_is_list($channels) || array_diff($channels, ['h5', 'miniapp']) !== []) {
            $this->fail($definition, "{$label} 的显示渠道不正确。");
        }
        $time = function (string $key) use ($style, $definition, $label): string {
            $value = $style[$key] ?? '';
            if (! is_string($value)) {
                $this->fail($definition, "{$label} 的显示时段格式不正确。");
            }
            $value = trim($value);
            if ($value !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
                $this->fail($definition, "{$label} 的显示时段必须为 YYYY-MM-DDTHH:mm 格式。");
            }

            return $value;
        };
        $daily = function (string $key) use ($style, $definition, $label): string {
            $value = $style[$key] ?? '';
            if (! is_string($value)) {
                $this->fail($definition, "{$label} 的每日显示时段格式不正确。");
            }
            $value = trim($value);
            if ($value !== '' && ! preg_match('/^\d{2}:\d{2}$/', $value)) {
                $this->fail($definition, "{$label} 的每日显示时段必须为 HH:mm 格式。");
            }

            return $value;
        };

        return [
            'margin' => $spacing('margin'),
            'padding' => $spacing('padding'),
            'radius' => $radius,
            'corner_radius' => $cornerRadius,
            'radius_corners' => $corners,
            'opacity' => $opacity,
            'display_daily_start' => $daily('display_daily_start'),
            'display_daily_end' => $daily('display_daily_end'),
            'background_type' => $backgroundType,
            'background_color' => $this->normalizeDecorationColor($definition, (string) ($style['background_color'] ?? ''), "{$label} 的背景色"),
            'background_image' => $this->normalizeDecorationUrl($definition, (string) ($style['background_image'] ?? ''), "{$label} 的背景图"),
            'background_gradient' => $this->decorationString($definition, $style, 'background_gradient', 120, "{$label} 的背景渐变"),
            'border' => is_bool($style['border'] ?? null) ? $style['border'] : false,
            'border_color' => $this->normalizeDecorationColor($definition, (string) ($style['border_color'] ?? ''), "{$label} 的边框色"),
            'shadow' => is_bool($style['shadow'] ?? null) ? $style['shadow'] : false,
            'channels' => array_values(array_unique($channels)) ?: ['h5', 'miniapp'],
            'display_start' => $time('display_start'),
            'display_end' => $time('display_end'),
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
            'banner' => (function () use ($definition, $props, $label, $integer, $boolean): array {
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
                    'duration_ms' => $integer('duration_ms', 200, 1000, 300),
                    'height_rpx' => $integer('height_rpx', 200, 600, 280),
                    'radius_rpx' => $integer('radius_rpx', 0, 48, 24),
                    'indicator' => in_array($props['indicator'] ?? '', ['dots', 'bars', 'numbers', 'none'], true) ? $props['indicator'] : 'dots',
                    'indicator_position' => in_array($props['indicator_position'] ?? '', ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'], true)
                        ? $props['indicator_position']
                        : 'bottom-right',
                    'show_title' => $boolean('show_title', true),
                    'data_source' => in_array($props['data_source'] ?? '', ['manual', 'platform'], true) ? $props['data_source'] : 'manual',
                    'limit' => $integer('limit', 1, 10, 5),
                ];
            })(),
            'announcement' => [
                'icon_text' => $string('icon_text', 2, '图标文字'),
                'icon_url' => $url('icon_url', '图标'),
                'title' => $string('title', 30, '标题'),
                'content' => $this->normalizeDecorationRichText($definition, (string) ($props['content'] ?? ''), 500, "{$label} 公告内容"),
                'link_type' => $link('link_type'),
                'link_value' => $linkValue(),
                'scroll' => $boolean('scroll', true),
                'speed' => $integer('speed', 1, 5, 3),
                'title_size_rpx' => $integer('title_size_rpx', 22, 36, 28),
                'text_color' => $this->normalizeDecorationColor($definition, (string) ($props['text_color'] ?? ''), "{$label} 公告文字颜色") ?: '#8A6A1F',
                'icon_size_rpx' => $integer('icon_size_rpx', 32, 72, 48),
                'show_arrow' => $boolean('show_arrow', true),
                'data_source' => in_array($props['data_source'] ?? '', ['manual', 'system'], true) ? $props['data_source'] : 'manual',
                'limit' => $integer('limit', 1, 10, 3),
            ],
            'quick_grid' => (function () use ($definition, $props, $label, $integer): array {
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
                        'badge_type' => in_array($item['badge_type'] ?? '', ['none', 'text', 'dot', 'image'], true) ? $item['badge_type'] : 'none',
                        'badge_text' => $this->decorationString($definition, $item, 'badge_text', 4, "{$label} 入口角标文字"),
                        'badge_image' => $this->normalizeDecorationUrl($definition, (string) ($item['badge_image'] ?? ''), "{$label} 入口角标图片"),
                    ];
                }

                return [
                    'columns' => $integer('columns', 3, 5, 4),
                    'items' => $items,
                    'icon_shape' => in_array($props['icon_shape'] ?? '', ['rounded', 'circle'], true) ? $props['icon_shape'] : 'rounded',
                    'icon_size_rpx' => $integer('icon_size_rpx', 56, 120, 84),
                    'label_size_rpx' => $integer('label_size_rpx', 20, 32, 24),
                    'label_color' => $this->normalizeDecorationColor($definition, (string) ($props['label_color'] ?? ''), "{$label} 入口文字颜色") ?: '#3C4961',
                    'row_gap_rpx' => $integer('row_gap_rpx', 0, 48, 20),
                    'data_source' => in_array($props['data_source'] ?? '', ['manual', 'product_category'], true) ? $props['data_source'] : 'manual',
                    'limit' => $integer('limit', 1, 10, 8),
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
                'columns' => $integer('columns', 1, 3, 2),
                'show_price' => $boolean('show_price', true),
                'show_subtitle' => $boolean('show_subtitle', true),
                'show_category' => $boolean('show_category', false),
            ],
            'post_list' => [
                'title' => $string('title', 20, '标题'),
                'subtitle' => $string('subtitle', 40, '副标题'),
                'limit' => $integer('limit', 1, 20, 6),
                'category_code' => $string('category_code', 32, '分类标识'),
                'layout' => in_array($props['layout'] ?? '', ['card', 'list'], true) ? $props['layout'] : 'card',
                'show_avatar' => $boolean('show_avatar', true),
                'show_excerpt' => $boolean('show_excerpt', false),
                'show_stats' => $boolean('show_stats', true),
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
            'nav_bar' => [
                'title' => $string('title', 20, '标题'),
                'subtitle' => $string('subtitle', 40, '副标题'),
                'link_type' => $link('link_type'),
                'show_border' => is_bool($props['show_border'] ?? null) ? $props['show_border'] : false,
            ],
            'search_box' => [
                'placeholder' => $string('placeholder', 20, '占位文案') ?: '搜索任务、商品或帖子',
                'link_type' => $link('link_type') ?: 'products',
            ],
            'category_nav' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的分类项必须为数组。");
                }
                if (count($rawItems) > 8) {
                    $this->fail($definition, "{$label} 最多支持 8 个分类项。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个分类项格式错误。');
                    }
                    $items[] = [
                        'label' => $this->decorationString($definition, $item, 'label', 8, "{$label} 分类名称"),
                        'category_id' => $this->decorationString($definition, $item, 'category_id', 64, "{$label} 分类标识"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 分类跳转类型"),
                    ];
                }

                return ['items' => $items];
            })(),
            'coupon' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的优惠券项必须为数组。");
                }
                if (count($rawItems) > 6) {
                    $this->fail($definition, "{$label} 最多支持 6 张优惠券。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 张优惠券格式错误。');
                    }
                    $items[] = [
                        'title' => $this->decorationString($definition, $item, 'title', 16, "{$label} 优惠券名称"),
                        'value_text' => $this->decorationString($definition, $item, 'value_text', 8, "{$label} 优惠额度"),
                        'condition' => $this->decorationString($definition, $item, 'condition', 20, "{$label} 使用条件"),
                        'button_text' => $this->decorationString($definition, $item, 'button_text', 10, "{$label} 按钮文字") ?: '立即领取',
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 优惠券跳转类型"),
                    ];
                }

                return [
                    'title' => $this->decorationString($definition, $props, 'title', 20, "{$label} 标题") ?: '优惠券',
                    'source' => in_array($props['source'] ?? '', ['manual', 'center'], true) ? $props['source'] : 'manual',
                    'limit' => $integer('limit', 1, 6, 3),
                    'items' => $items,
                ];
            })(),
            'floating_box' => [
                'image_url' => $url('image_url', '悬浮图片'),
                'icon_text' => $string('icon_text', 2, '图标文字') ?: '客',
                'link_type' => $link('link_type'),
                'bottom_rpx' => $integer('bottom_rpx', 80, 400, 180),
            ],
            'page_title' => [
                'title' => $string('title', 30, '标题') ?: '页面标题',
                'subtitle' => $string('subtitle', 60, '副标题'),
                'align' => in_array($props['align'] ?? '', ['left', 'center'], true) ? $props['align'] : 'left',
            ],
            'list_nav' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的入口必须为数组。");
                }
                if (count($rawItems) > 6) {
                    $this->fail($definition, "{$label} 最多支持 6 个入口。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个入口格式错误。');
                    }
                    $items[] = [
                        'icon_url' => $this->normalizeDecorationUrl($definition, (string) ($item['icon_url'] ?? ''), "{$label} 入口图标"),
                        'title' => $this->decorationString($definition, $item, 'title', 20, "{$label} 入口标题") ?: '入口',
                        'subtitle' => $this->decorationString($definition, $item, 'subtitle', 40, "{$label} 入口描述"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 入口跳转类型"),
                    ];
                }

                return ['items' => $items];
            })(),
            'tag_group' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的标签必须为数组。");
                }
                if (count($rawItems) > 10) {
                    $this->fail($definition, "{$label} 最多支持 10 个标签。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个标签格式错误。');
                    }
                    $items[] = [
                        'label' => $this->decorationString($definition, $item, 'label', 12, "{$label} 标签文字"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 标签跳转类型"),
                    ];
                }

                return [
                    'title' => $this->decorationString($definition, $props, 'title', 20, "{$label} 标题"),
                    'items' => $items,
                ];
            })(),
            'image_group' => (function () use ($definition, $props, $label, $integer): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的图片必须为数组。");
                }
                if (count($rawItems) > 9) {
                    $this->fail($definition, "{$label} 最多支持 9 张图片。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 张图片格式错误。');
                    }
                    $items[] = [
                        'image_url' => $this->normalizeDecorationUrl($definition, (string) ($item['image_url'] ?? ''), "{$label} 第 ".($index + 1).' 张图片'),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 图片跳转类型"),
                    ];
                }

                return ['columns' => $integer('columns', 2, 3, 2), 'items' => $items];
            })(),
            'button_group' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的按钮必须为数组。");
                }
                if (count($rawItems) > 3) {
                    $this->fail($definition, "{$label} 最多支持 3 个按钮。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个按钮格式错误。');
                    }
                    $items[] = [
                        'label' => $this->decorationString($definition, $item, 'label', 10, "{$label} 按钮文字") ?: '立即查看',
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 按钮跳转类型"),
                    ];
                }

                return ['items' => $items];
            })(),
            'divider' => [
                'line_style' => in_array($props['line_style'] ?? '', ['solid', 'dashed'], true) ? $props['line_style'] : 'solid',
                'color' => $this->normalizeDecorationColor($definition, (string) ($props['color'] ?? ''), "{$label} 辅助线颜色") ?: '#E7EBF2',
            ],
            'activity_group' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的活动必须为数组。");
                }
                if (count($rawItems) > 4) {
                    $this->fail($definition, "{$label} 最多支持 4 个活动。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个活动格式错误。');
                    }
                    $items[] = [
                        'image_url' => $this->normalizeDecorationUrl($definition, (string) ($item['image_url'] ?? ''), "{$label} 活动图片"),
                        'title' => $this->decorationString($definition, $item, 'title', 20, "{$label} 活动标题") ?: '活动',
                        'subtitle' => $this->decorationString($definition, $item, 'subtitle', 40, "{$label} 活动说明"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 活动跳转类型"),
                    ];
                }

                return [
                    'title' => $this->decorationString($definition, $props, 'title', 20, "{$label} 标题"),
                    'items' => $items,
                ];
            })(),
            'member_card' => [
                'title' => $string('title', 20, '标题') ?: '会员中心',
                'description' => $string('description', 60, '描述'),
                'button_text' => $string('button_text', 10, '按钮文字') ?: '去开通',
                'link_type' => $link('link_type'),
            ],
            'cart_entry' => [
                'title' => $string('title', 20, '标题') ?: '购物车',
                'description' => $string('description', 60, '描述'),
                'button_text' => $string('button_text', 10, '按钮文字') ?: '去结算',
            ],
            'qr_code' => [
                'image_url' => $url('image_url', '二维码图片'),
                'title' => $string('title', 20, '标题') ?: '扫码加入',
                'description' => $string('description', 60, '描述'),
            ],
            'location_search' => [
                'campus_text' => $string('campus_text', 12, '校区文字') ?: '选择校区',
                'placeholder' => $string('placeholder', 20, '占位文案') ?: '搜索任务、商品或帖子',
                'link_type' => $link('link_type') ?: 'products',
            ],
            'order_marquee' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的动态必须为数组。");
                }
                if (count($rawItems) > 8) {
                    $this->fail($definition, "{$label} 最多支持 8 条动态。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 条动态格式错误。');
                    }
                    $items[] = [
                        'text' => $this->decorationString($definition, $item, 'text', 40, "{$label} 动态文字"),
                    ];
                }

                return [
                    'icon_text' => $this->decorationString($definition, $props, 'icon_text', 2, "{$label} 图标文字") ?: '动',
                    'items' => $items,
                    'link_type' => $this->decorationLink($definition, $props, 'link_type', "{$label} 跳转类型"),
                    'speed' => $integer('speed', 1, 5, 3),
                    'bg_color' => $this->normalizeDecorationColor($definition, (string) ($props['bg_color'] ?? ''), "{$label} 弹幕背景色") ?: '#FFF8E8',
                    'text_color' => $this->normalizeDecorationColor($definition, (string) ($props['text_color'] ?? ''), "{$label} 弹幕文字色") ?: '#8A6A1F',
                    'opacity' => $integer('opacity', 20, 100, 100),
                ];
            })(),
            'video' => [
                'video_url' => $url('video_url', '视频地址'),
                'poster_url' => $url('poster_url', '视频封面'),
                'height_rpx' => $integer('height_rpx', 180, 600, 220),
                'ratio' => in_array($props['ratio'] ?? '', ['auto', '16:9', '4:3', '1:1'], true) ? $props['ratio'] : 'auto',
            ],
            'tab_nav' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的选项卡必须为数组。");
                }
                if (count($rawItems) > 6) {
                    $this->fail($definition, "{$label} 最多支持 6 个选项卡。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个选项卡格式错误。');
                    }
                    $items[] = [
                        'label' => $this->decorationString($definition, $item, 'label', 8, "{$label} 选项卡文字") ?: '选项',
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 选项卡跳转类型"),
                    ];
                }

                return [
                    'items' => $items,
                    'active_color' => $this->normalizeDecorationColor($definition, (string) ($props['active_color'] ?? ''), "{$label} 选项卡激活色") ?: '#0BBF63',
                    'background_color' => $this->normalizeDecorationColor($definition, (string) ($props['background_color'] ?? ''), "{$label} 选项卡底色") ?: '#F2F4F8',
                    'sticky' => $boolean('sticky', false),
                ];
            })(),
            'ad_banner' => [
                'image_url' => $url('image_url', '广告图片'),
                'link_type' => $link('link_type'),
                'link_value' => $this->decorationString($definition, $props, 'link_value', 255, "{$label} 跳转地址"),
                'height_rpx' => $integer('height_rpx', 80, 600, 160),
                'show_badge' => is_bool($props['show_badge'] ?? null) ? $props['show_badge'] : true,
            ],
            'image_showcase' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的图片必须为数组。");
                }
                if (count($rawItems) > 3) {
                    $this->fail($definition, "{$label} 最多支持 3 张图片。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 张图片格式错误。');
                    }
                    $items[] = [
                        'image_url' => $this->normalizeDecorationUrl($definition, (string) ($item['image_url'] ?? ''), "{$label} 第 ".($index + 1).' 张图片'),
                        'title' => $this->decorationString($definition, $item, 'title', 16, "{$label} 图片标题"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 图片跳转类型"),
                    ];
                }

                return [
                    'layout' => in_array($props['layout'] ?? '', ['left', 'right'], true) ? $props['layout'] : 'left',
                    'items' => $items,
                ];
            })(),
            'notice_bar' => [
                'tone' => in_array($props['tone'] ?? '', ['info', 'warning', 'success', 'normal'], true) ? $props['tone'] : 'warning',
                'title' => $string('title', 20, '标题') ?: '温馨提示',
                'content' => $string('content', 80, '内容'),
                'link_type' => $link('link_type'),
                'show_icon' => $boolean('show_icon', true),
                'title_size_rpx' => $integer('title_size_rpx', 22, 36, 26),
                'content_size_rpx' => $integer('content_size_rpx', 20, 32, 22),
            ],
            'hot_tags' => (function () use ($definition, $props, $label): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的标签必须为数组。");
                }
                if (count($rawItems) > 10) {
                    $this->fail($definition, "{$label} 最多支持 10 个标签。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 个标签格式错误。');
                    }
                    $items[] = [
                        'label' => $this->decorationString($definition, $item, 'label', 12, "{$label} 标签文字"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 标签跳转类型"),
                        'hot' => is_bool($item['hot'] ?? null) ? $item['hot'] : false,
                    ];
                }

                return [
                    'title' => $this->decorationString($definition, $props, 'title', 20, "{$label} 标题") ?: '热门标签',
                    'items' => $items,
                ];
            })(),
            'title_bar' => [
                'title' => $string('title', 20, '标题') ?: '标题',
                'subtitle' => $string('subtitle', 40, '副标题'),
                'more_text' => $string('more_text', 8, '更多文字') ?: '更多',
                'link_type' => $link('link_type'),
                'title_size_rpx' => $integer('title_size_rpx', 26, 40, 32),
                'title_color' => $this->normalizeDecorationColor($definition, (string) ($props['title_color'] ?? ''), "{$label} 标题颜色") ?: '#1F2937',
                'more_color' => $this->normalizeDecorationColor($definition, (string) ($props['more_color'] ?? ''), "{$label} 更多文字颜色") ?: '#0BBF63',
            ],
            'tile_ad' => (function () use ($definition, $props, $label, $url, $link, $integer): array {
                $rawItems = $props['items'] ?? [];
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    $this->fail($definition, "{$label} 的瓷片必须为数组。");
                }
                if (count($rawItems) > 3) {
                    $this->fail($definition, "{$label} 最多支持 3 块瓷片。");
                }
                $items = [];
                foreach ($rawItems as $index => $item) {
                    if (! is_array($item) || array_is_list($item)) {
                        $this->fail($definition, "{$label} 第 ".($index + 1).' 块瓷片格式错误。');
                    }
                    $items[] = [
                        'image_url' => $this->normalizeDecorationUrl($definition, (string) ($item['image_url'] ?? ''), "{$label} 第 ".($index + 1).' 块瓷片'),
                        'title' => $this->decorationString($definition, $item, 'title', 16, "{$label} 瓷片主标题"),
                        'subtitle' => $this->decorationString($definition, $item, 'subtitle', 24, "{$label} 瓷片副标题"),
                        'tag' => $this->decorationString($definition, $item, 'tag', 6, "{$label} 瓷片标签"),
                        'link_type' => $this->decorationLink($definition, $item, 'link_type', "{$label} 瓷片跳转类型"),
                        'link_value' => $this->normalizeDecorationUrl($definition, (string) ($item['link_value'] ?? ''), "{$label} 瓷片跳转地址"),
                    ];
                }

                return [
                    'layout' => in_array($props['layout'] ?? '', ['big-left', 'big-right', 'three'], true) ? $props['layout'] : 'big-left',
                    'radius_rpx' => $integer('radius_rpx', 0, 32, 12),
                    'gap_rpx' => $integer('gap_rpx', 0, 24, 8),
                    'title_size_rpx' => $integer('title_size_rpx', 24, 40, 30),
                    'title_color' => $this->normalizeDecorationColor($definition, (string) ($props['title_color'] ?? ''), "{$label} 瓷片标题颜色") ?: '#FFFFFF',
                    'subtitle_size_rpx' => $integer('subtitle_size_rpx', 20, 30, 22),
                    'subtitle_color' => $this->normalizeDecorationColor($definition, (string) ($props['subtitle_color'] ?? ''), "{$label} 瓷片副标题颜色") ?: '#F2F4F8',
                    'tag_bg' => $this->normalizeDecorationColor($definition, (string) ($props['tag_bg'] ?? ''), "{$label} 瓷片标签底色") ?: '#0BBF63',
                    'tag_color' => $this->normalizeDecorationColor($definition, (string) ($props['tag_color'] ?? ''), "{$label} 瓷片标签文字颜色") ?: '#FFFFFF',
                    'items' => $items,
                ];
            })(),
            'popup' => [
                'image_url' => $url('image_url', '弹窗图片'),
                'title' => $string('title', 20, '标题'),
                'content' => $string('content', 60, '内容'),
                'button_text' => $string('button_text', 10, '按钮文字') ?: '立即查看',
                'link_type' => $link('link_type'),
                'frequency' => in_array($props['frequency'] ?? '', ['once', 'daily', 'every'], true) ? $props['frequency'] : 'daily',
                'closable' => $boolean('closable', true),
                'width_rpx' => $integer('width_rpx', 480, 660, 560),
                'radius_rpx' => $integer('radius_rpx', 0, 32, 20),
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

    private function normalizeDecorationColor(SystemSettingDefinition $definition, string $color, string $label): string
    {
        $color = trim($color);
        if ($color === '') {
            return '';
        }
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $this->fail($definition, "{$label} 必须为 #RRGGBB 格式的颜色值。");
        }

        return strtoupper($color);
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
