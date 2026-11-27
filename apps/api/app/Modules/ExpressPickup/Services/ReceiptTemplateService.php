<?php

namespace App\Modules\ExpressPickup\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\ExpressPickup\Support\ReceiptTemplateCompiler;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Str;
use Throwable;

/**
 * 小票打印模板：装修中心保存多套模板，按打印场景绑定后由现有小票机流程消费。
 */
class ReceiptTemplateService
{
    public const SETTING_KEY = 'decoration.receipt_templates';

    public const SCENES = [
        'express_pickup' => '快递代取',
        'delivery' => '外卖',
        'pickup' => '自提',
        'dine_in' => '堂食',
        'wechat_reserve' => '微信预定',
    ];

    public const SLOTS = ['receipt' => '整单', 'split' => '分单', 'label' => '标签'];

    /** 目前已接入真实打印的场景，其余场景先保存绑定，待订单模块接入后生效。 */
    public const ACTIVE_SCENES = ['express_pickup'];

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ReceiptTemplateCompiler $compiler,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $resolved = $this->settings->read(self::SETTING_KEY);
        $config = $this->normalize($resolved['value']);
        $campusNames = Campus::query()->whereIn('id', collect($config['templates'])->pluck('campus_id')->filter()->unique()->values())->pluck('name', 'id');

        return [
            'schema_version' => $config['schema_version'],
            'templates' => array_map(fn (array $template): array => [
                ...$template,
                'campus_name' => $template['campus_id'] === null ? '全站' : (string) ($campusNames[$template['campus_id']] ?? '已删除校区'),
            ], $config['templates']),
            'bindings' => $config['bindings'],
            'scenes' => collect(self::SCENES)->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
                'status' => in_array($code, self::ACTIVE_SCENES, true) ? 'active' : 'pending',
            ])->values()->all(),
            'slots' => collect(self::SLOTS)->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
            ])->values()->all(),
            'block_types' => collect(ReceiptTemplateCompiler::BLOCK_TYPES)->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
            ])->values()->all(),
            'variables' => collect($this->compiler->variables())->map(fn (string $label, string $key): array => [
                'key' => '${'.$key.'}',
                'label' => $label,
            ])->values()->all(),
            'version' => $resolved['version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(?string $id, array $input, AdminUser $actor): array
    {
        $config = $this->storedConfig();
        $index = $id === null ? null : $this->indexOf($config, $id);
        if ($id !== null && $index === null) {
            $this->notFound();
        }
        $template = [
            'id' => $id ?? 'rcp-'.strtolower((string) Str::ulid()),
            'name' => (string) $input['name'],
            'campus_id' => ($input['campus_id'] ?? null) === null ? null : (int) $input['campus_id'],
            'paper_width' => (int) ($input['paper_width'] ?? 58),
            'print_type' => (string) ($input['print_type'] ?? 'express_pickup'),
            'status' => (string) ($input['status'] ?? 'enabled'),
            'blocks' => $this->normalizeBlocks($input['blocks'] ?? null),
            'created_at' => $index === null
                ? now()->toIso8601String()
                : (string) ($config['templates'][$index]['created_at'] ?? now()->toIso8601String()),
            'updated_at' => now()->toIso8601String(),
        ];
        if ($index === null) {
            $config['templates'][] = $template;
        } else {
            $config['templates'][$index] = $template;
        }
        $this->persist($config, $actor, ($index === null ? '新建' : '保存').'小票模板：'.$template['name']);

        return $this->find($this->storedConfig(), $template['id']) ?? $template;
    }

    /** @return array<string, mixed> */
    public function duplicate(string $id, AdminUser $actor): array
    {
        $config = $this->storedConfig();
        $source = $this->find($config, $id);
        if ($source === null) {
            $this->notFound();
        }
        $copy = [
            ...$source,
            'id' => 'rcp-'.strtolower((string) Str::ulid()),
            'name' => mb_substr($source['name'].' 副本', 0, 40),
            'status' => 'disabled',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $config['templates'][] = $copy;
        $this->persist($config, $actor, '复制小票模板：'.$source['name']);

        return $this->find($this->storedConfig(), $copy['id']) ?? $copy;
    }

    /** @return array<string, mixed> */
    public function toggle(string $id, AdminUser $actor): array
    {
        $config = $this->storedConfig();
        $index = $this->indexOf($config, $id);
        if ($index === null) {
            $this->notFound();
        }
        $status = $config['templates'][$index]['status'] === 'enabled' ? 'disabled' : 'enabled';
        $config['templates'][$index]['status'] = $status;
        $config['templates'][$index]['updated_at'] = now()->toIso8601String();
        $name = (string) $config['templates'][$index]['name'];
        $this->persist($config, $actor, ($status === 'enabled' ? '启用' : '停用').'小票模板：'.$name);

        return $this->find($this->storedConfig(), $id) ?? $config['templates'][$index];
    }

    public function remove(string $id, AdminUser $actor): void
    {
        $config = $this->storedConfig();
        $index = $this->indexOf($config, $id);
        if ($index === null) {
            $this->notFound();
        }
        $name = (string) $config['templates'][$index]['name'];
        unset($config['templates'][$index]);
        $config['templates'] = array_values($config['templates']);
        foreach ($config['bindings'] as $scene => $slots) {
            foreach ($slots as $slot => $templateId) {
                if ($templateId === $id) {
                    $config['bindings'][$scene][$slot] = null;
                }
            }
        }
        $this->persist($config, $actor, '删除小票模板：'.$name);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public function saveBindings(array $bindings, AdminUser $actor): array
    {
        $config = $this->storedConfig();
        $config['bindings'] = $this->normalizeBindings($bindings, array_column($config['templates'], 'id'));
        $this->persist($config, $actor, '调整打印模板绑定。');

        return $this->overview();
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array{content: string, preview: string}
     */
    public function preview(array $blocks, int $paperWidth): array
    {
        $template = ['paper_width' => $paperWidth, 'blocks' => $blocks];
        $variables = $this->sampleVariables();

        return [
            'content' => $this->compiler->compile($template, $variables),
            'preview' => $this->compiler->compile($template, $variables, true),
        ];
    }

    /**
     * 打印时取场景绑定的启用模板；未绑定、已停用或配置缺失时返回 null，调用方回落到小票机自带模板。
     *
     * @param  array<string, string>  $variables
     */
    public function compiledForScene(string $scene, array $variables, ?int $campusId = null): ?string
    {
        try {
            $config = $this->storedConfig();
        } catch (Throwable) {
            return null;
        }
        $templateId = $config['bindings'][$scene]['receipt'] ?? null;
        if ($templateId === null) {
            return null;
        }
        $template = $this->find($config, $templateId);
        if ($template === null || $template['status'] !== 'enabled') {
            return null;
        }
        if ($template['campus_id'] !== null && $campusId !== null && $template['campus_id'] !== $campusId) {
            return null;
        }

        return $this->compiler->compile($template, $variables);
    }

    /** @return array<string, string> */
    public function sampleVariables(): array
    {
        return [
            'order_no' => 'EP20260101120000ABCDEF',
            'pickup_site' => '菜鸟驿站（东门）',
            'pickup_code' => '8-2-3021',
            'package_info' => '纸箱小件',
            'package_count' => '1',
            'deliver_building' => '学生公寓 3 号楼',
            'deliver_address' => '3-412',
            'contact_name' => '张同学',
            'contact_phone' => '13800001234',
            'reward' => '5.00',
            'expected_at' => '18:00 前',
            'remark' => '轻拿轻放',
            'created_at' => now()->format('Y-m-d H:i'),
            'campus_name' => '示例校区',
            'product_name' => '示例商品',
            'quantity' => '1',
            'amount' => '9.90',
            'distribution_code' => 'D20260101',
        ];
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $value): array
    {
        $config = ['schema_version' => 1, 'templates' => [], 'bindings' => $this->emptyBindings()];
        if (! is_array($value) || array_is_list($value)) {
            return $config;
        }
        if (is_int($value['schema_version'] ?? null) && $value['schema_version'] > 0) {
            $config['schema_version'] = $value['schema_version'];
        }
        $templates = $value['templates'] ?? null;
        if (is_array($templates) && array_is_list($templates)) {
            $config['templates'] = array_values(array_filter(
                $templates,
                fn ($template) => is_array($template)
                    && ! array_is_list($template)
                    && is_string($template['id'] ?? null)
                    && is_string($template['name'] ?? null)
                    && is_array($template['blocks'] ?? null)
                    && array_is_list($template['blocks']),
            ));
        }
        $bindings = is_array($value['bindings'] ?? null) && ! array_is_list($value['bindings']) ? $value['bindings'] : [];

        $config['bindings'] = $this->normalizeBindings($bindings, array_column($config['templates'], 'id'));

        return $config;
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @param  array<int, string>  $templateIds
     * @return array<string, array<string, string|null>>
     */
    private function normalizeBindings(array $bindings, array $templateIds): array
    {
        $normalized = $this->emptyBindings();
        foreach (array_keys(self::SCENES) as $scene) {
            $sceneValue = $bindings[$scene] ?? null;
            if (! is_array($sceneValue) || array_is_list($sceneValue)) {
                continue;
            }
            foreach (array_keys(self::SLOTS) as $slot) {
                $templateId = $sceneValue[$slot] ?? null;
                $normalized[$scene][$slot] = is_string($templateId) && in_array($templateId, $templateIds, true)
                    ? $templateId
                    : null;
            }
        }

        return $normalized;
    }

    /** @return array<string, array<string, string|null>> */
    private function emptyBindings(): array
    {
        $bindings = [];
        foreach (array_keys(self::SCENES) as $scene) {
            $bindings[$scene] = [];
            foreach (array_keys(self::SLOTS) as $slot) {
                $bindings[$scene][$slot] = null;
            }
        }

        return $bindings;
    }

    /** @return array<string, mixed> */
    private function storedConfig(): array
    {
        return $this->normalize($this->settings->read(self::SETTING_KEY)['value']);
    }

    /** @return array<string, mixed>|null */
    private function find(array $config, string $id): ?array
    {
        $index = $this->indexOf($config, $id);

        return $index === null ? null : $config['templates'][$index];
    }

    private function indexOf(array $config, string $id): ?int
    {
        foreach ($config['templates'] as $index => $template) {
            if (($template['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<string, mixed>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return [];
        }
        $normalized = [];
        foreach (array_slice($blocks, 0, 40) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if (! isset(ReceiptTemplateCompiler::BLOCK_TYPES[$type])) {
                continue;
            }
            $id = $block['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $id = 'blk-'.($index + 1).'-'.substr(md5(uniqid('', true)), 0, 6);
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'props' => [
                    'content' => is_string($props['content'] ?? null) ? mb_substr($props['content'], 0, 500) : '',
                    'align' => in_array($props['align'] ?? 'left', ['left', 'center', 'right'], true) ? $props['align'] : 'left',
                    'font_size' => is_int($props['font_size'] ?? null) ? max(0, min(2, $props['font_size'])) : 0,
                    'bold' => is_int($props['bold'] ?? null) ? max(0, min(2, $props['bold'])) : 0,
                ],
            ];
        }

        return $normalized;
    }

    private function notFound(): never
    {
        throw new SettingException('RECEIPT_TEMPLATE_NOT_FOUND', '小票模板不存在或已被删除。', 404);
    }

    private function persist(array $config, AdminUser $actor, string $reason): void
    {
        $this->settings->saveDraft(self::SETTING_KEY, [
            'schema_version' => $config['schema_version'],
            'templates' => $config['templates'],
            'bindings' => $config['bindings'],
        ], $reason, $actor);
        $this->settings->publish(self::SETTING_KEY, $actor);
    }
}
