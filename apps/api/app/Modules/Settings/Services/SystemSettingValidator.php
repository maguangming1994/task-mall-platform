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

    private function fail(SystemSettingDefinition $definition, string $message): never
    {
        throw ValidationException::withMessages(['value' => ["{$definition->name}：{$message}"]]);
    }
}
