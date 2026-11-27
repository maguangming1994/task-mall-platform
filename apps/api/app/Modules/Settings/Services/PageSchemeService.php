<?php

namespace App\Modules\Settings\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingDraft;
use Illuminate\Support\Str;

/**
 * 页面装修方案：同一页面保存多套方案，按公众号（H5）/小程序渠道选择生效。
 *
 * 方案与生效指针保存在独立设置键中，启用公众号渠道时把方案内容镜像写入已发布的
 * decoration.pages，用户端读取逻辑与旧配置完全不用改动。
 */
class PageSchemeService
{
    public const SETTING_KEY = 'decoration.schemes';

    public const CHANNELS = ['h5', 'miniapp'];

    private const PAGE_LABELS = [
        'home' => '首页',
        'community' => '社区',
        'mall' => '商城',
        'account' => '我的',
    ];

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PageDecorationService $pages,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $resolved = $this->settings->read(self::SETTING_KEY);
        $stored = $this->normalize($resolved['value']);
        $storedIds = array_column($stored['schemes'], 'id');
        $config = $this->withDefaults($stored);

        return [
            'schema_version' => $config['schema_version'],
            'pages' => $config['pages'],
            'schemes' => array_map(
                fn (array $scheme): array => [...$scheme, 'derived' => ! in_array($scheme['id'], $storedIds, true)],
                $config['schemes'],
            ),
            'page_types' => collect(PageDecorationService::PAGES)->map(fn (string $key): array => [
                'key' => $key,
                'label' => self::PAGE_LABELS[$key] ?? $key,
            ])->values()->all(),
            'channels' => [
                ['key' => 'h5', 'label' => '公众号', 'effective' => true],
                ['key' => 'miniapp', 'label' => '小程序', 'effective' => false],
            ],
            'version' => $resolved['version'],
        ];
    }

    /** @return array<string, mixed> */
    public function create(string $name, string $pageKey, AdminUser $actor): array
    {
        $config = $this->materialized();
        $page = $this->pages->published()['pages'][$pageKey];
        $scheme = [
            'id' => 'sch-'.strtolower((string) Str::ulid()),
            'name' => $name,
            'page_key' => $pageKey,
            'page' => $page,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $config['schemes'][] = $scheme;
        $this->persist($config, $actor, '新建页面方案：'.$name);

        return $this->findScheme($this->storedConfig(), $scheme['id']) ?? $scheme;
    }

    /** @param  array<string, mixed>  $page */
    public function update(string $id, string $name, array $page, AdminUser $actor): array
    {
        $config = $this->materialized();
        $index = $this->indexOf($config, $id);
        if ($index === null) {
            $this->notFound();
        }
        $wasActive = $this->isChannelActive($config, $id, 'h5');
        if ($wasActive) {
            $this->assertPagesDraftAbsent();
        }
        $config['schemes'][$index]['name'] = $name;
        $config['schemes'][$index]['page'] = $this->normalizePage($page);
        $config['schemes'][$index]['updated_at'] = now()->toIso8601String();
        $this->persist($config, $actor, '保存页面方案：'.$name);

        $stored = $this->findScheme($this->storedConfig(), $id);
        if ($stored !== null && $wasActive) {
            $this->mirror($stored, $actor);
        }

        return $stored ?? $config['schemes'][$index];
    }

    /** @return array<string, mixed> */
    public function duplicate(string $id, AdminUser $actor): array
    {
        $config = $this->materialized();
        $source = $this->findScheme($config, $id);
        if ($source === null) {
            $this->notFound();
        }
        $copy = [
            ...$source,
            'id' => 'sch-'.strtolower((string) Str::ulid()),
            'name' => mb_substr($source['name'].' 副本', 0, 40),
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $config['schemes'][] = $copy;
        $this->persist($config, $actor, '复制页面方案：'.$source['name']);

        return $this->findScheme($this->storedConfig(), $copy['id']) ?? $copy;
    }

    /** @return array<string, mixed> */
    public function setChannel(string $id, string $channel, bool $enabled, AdminUser $actor): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new SettingException('DECORATION_CHANNEL_INVALID', '不支持的装修渠道。', 422);
        }
        $config = $this->materialized();
        $scheme = $this->findScheme($config, $id);
        if ($scheme === null) {
            $this->notFound();
        }
        $pageKey = $scheme['page_key'];
        $current = $config['pages'][$pageKey][$channel] ?? null;
        $config['pages'][$pageKey][$channel] = $enabled
            ? $id
            : ($current === $id ? null : $current);
        if ($enabled && $channel === 'h5') {
            // 先确认线上装修没有未发布草稿，避免方案指针已切换但页面未同步。
            $this->assertPagesDraftAbsent();
        }
        $action = $enabled ? '启用' : '停用';
        $this->persist($config, $actor, "{$action}页面方案：{$scheme['name']}");

        if ($enabled && $channel === 'h5') {
            $stored = $this->findScheme($this->storedConfig(), $id);
            if ($stored !== null) {
                $this->mirror($stored, $actor);
            }
        }

        return $this->overview();
    }

    public function remove(string $id, AdminUser $actor): void
    {
        if (str_starts_with($id, 'default-')) {
            throw new SettingException('DECORATION_SCHEME_DERIVED', '默认方案由当前线上配置派生，请先新建并启用其他方案再删除。', 409);
        }
        $config = $this->materialized();
        $index = $this->indexOf($config, $id);
        if ($index === null) {
            $this->notFound();
        }
        foreach (PageDecorationService::PAGES as $pageKey) {
            foreach (self::CHANNELS as $channel) {
                if (($config['pages'][$pageKey][$channel] ?? null) === $id) {
                    throw new SettingException('DECORATION_SCHEME_ACTIVE', '该方案正在生效，请先切换到其他方案再删除。', 409);
                }
            }
        }
        $name = (string) $config['schemes'][$index]['name'];
        unset($config['schemes'][$index]);
        $config['schemes'] = array_values($config['schemes']);
        $this->persist($config, $actor, '删除页面方案：'.$name);
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $value): array
    {
        $pages = [];
        foreach (PageDecorationService::PAGES as $pageKey) {
            $pages[$pageKey] = ['h5' => null, 'miniapp' => null];
        }
        $config = ['schema_version' => 1, 'pages' => $pages, 'schemes' => []];
        if (! is_array($value) || array_is_list($value)) {
            return $config;
        }
        if (is_int($value['schema_version'] ?? null) && $value['schema_version'] > 0) {
            $config['schema_version'] = $value['schema_version'];
        }
        $sourcePages = is_array($value['pages'] ?? null) && ! array_is_list($value['pages']) ? $value['pages'] : [];
        foreach (PageDecorationService::PAGES as $pageKey) {
            $channels = $sourcePages[$pageKey] ?? null;
            if (! is_array($channels) || array_is_list($channels)) {
                continue;
            }
            foreach (self::CHANNELS as $channel) {
                $pointer = $channels[$channel] ?? null;
                $config['pages'][$pageKey][$channel] = is_string($pointer) && $pointer !== '' ? $pointer : null;
            }
        }
        $schemes = $value['schemes'] ?? null;
        if (is_array($schemes) && array_is_list($schemes)) {
            $config['schemes'] = array_values(array_filter(
                $schemes,
                fn ($scheme) => is_array($scheme)
                    && ! array_is_list($scheme)
                    && is_string($scheme['id'] ?? null)
                    && is_string($scheme['page_key'] ?? null)
                    && in_array($scheme['page_key'], PageDecorationService::PAGES, true)
                    && is_array($scheme['page'] ?? null)
                    && ! array_is_list($scheme['page']),
            ));
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function storedConfig(): array
    {
        return $this->normalize($this->settings->read(self::SETTING_KEY)['value']);
    }

    /**
     * 把“当前线上配置”派生为默认方案，保证老部署打开方案列表时也有可装修的内容；
     * 默认方案在首次执行写操作时落库，之后按普通方案管理。
     *
     * @return array<string, mixed>
     */
    private function materialized(): array
    {
        $config = $this->storedConfig();
        $live = null;
        foreach (PageDecorationService::PAGES as $pageKey) {
            if ($this->pageHasScheme($config, $pageKey)) {
                continue;
            }
            $live ??= $this->pages->published()['pages'];
            $page = $this->normalizePage($live[$pageKey]);
            $id = 'default-'.$pageKey;
            $config['schemes'][] = [
                'id' => $id,
                'name' => ($page['name'] !== '' ? $page['name'] : (self::PAGE_LABELS[$pageKey] ?? $pageKey)).'默认方案',
                'page_key' => $pageKey,
                'page' => $page,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ];
            if (($config['pages'][$pageKey]['h5'] ?? null) === null) {
                $config['pages'][$pageKey]['h5'] = $id;
            }
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function withDefaults(array $config): array
    {
        $live = null;
        foreach (PageDecorationService::PAGES as $pageKey) {
            if ($this->pageHasScheme($config, $pageKey)) {
                continue;
            }
            $live ??= $this->pages->published()['pages'];
            $page = $this->normalizePage($live[$pageKey]);
            $id = 'default-'.$pageKey;
            $config['schemes'][] = [
                'id' => $id,
                'name' => ($page['name'] !== '' ? $page['name'] : (self::PAGE_LABELS[$pageKey] ?? $pageKey)).'默认方案',
                'page_key' => $pageKey,
                'page' => $page,
                'created_at' => '',
                'updated_at' => '',
            ];
            if (($config['pages'][$pageKey]['h5'] ?? null) === null) {
                $config['pages'][$pageKey]['h5'] = $id;
            }
        }

        return $config;
    }

    private function pageHasScheme(array $config, string $pageKey): bool
    {
        foreach ($config['schemes'] as $scheme) {
            if (($scheme['page_key'] ?? null) === $pageKey) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function findScheme(array $config, string $id): ?array
    {
        $index = $this->indexOf($config, $id);

        return $index === null ? null : $config['schemes'][$index];
    }

    private function indexOf(array $config, string $id): ?int
    {
        foreach ($config['schemes'] as $index => $scheme) {
            if (($scheme['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        return null;
    }

    private function isChannelActive(array $config, string $id, string $channel): bool
    {
        foreach (PageDecorationService::PAGES as $pageKey) {
            if (($config['pages'][$pageKey][$channel] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    private function notFound(): never
    {
        throw new SettingException('DECORATION_SCHEME_NOT_FOUND', '页面方案不存在或已被删除。', 404);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function normalizePage(array $page): array
    {
        return [
            'name' => is_string($page['name'] ?? null) ? $page['name'] : '',
            'title' => is_string($page['title'] ?? null) ? $page['title'] : '',
            'description' => is_string($page['description'] ?? null) ? $page['description'] : '',
            'cover_url' => is_string($page['cover_url'] ?? null) ? $page['cover_url'] : '',
            'background_color' => is_string($page['background_color'] ?? null) ? $page['background_color'] : '',
            'background_url' => is_string($page['background_url'] ?? null) ? $page['background_url'] : '',
            'sections' => is_array($page['sections'] ?? null) && array_is_list($page['sections']) ? $page['sections'] : [],
        ];
    }

    private function persist(array $config, AdminUser $actor, string $reason): void
    {
        $this->settings->saveDraft(self::SETTING_KEY, [
            'schema_version' => $config['schema_version'],
            'pages' => $config['pages'],
            'schemes' => $config['schemes'],
        ], $reason, $actor);
        $this->settings->publish(self::SETTING_KEY, $actor);
    }

    /**
     * 启用公众号渠道时把方案内容写入已发布的页面装修配置，用户端保持零改动。
     *
     * @param  array<string, mixed>  $scheme
     */
    private function mirror(array $scheme, AdminUser $actor): void
    {
        $this->assertPagesDraftAbsent();
        $published = $this->pages->published();
        $pages = $published['pages'];
        $pageKey = (string) $scheme['page_key'];
        $pages[$pageKey] = $this->normalizePage($scheme['page']);

        $this->settings->saveDraft(PageDecorationService::SETTING_KEY, [
            'schema_version' => max(1, (int) $published['schema_version']),
            'pages' => $pages,
        ], '启用页面方案：'.$scheme['name'], $actor);
        $this->settings->publish(PageDecorationService::SETTING_KEY, $actor);
    }

    private function assertPagesDraftAbsent(): void
    {
        $definition = SystemSettingDefinition::query()
            ->where('setting_key', PageDecorationService::SETTING_KEY)
            ->first();
        if ($definition !== null && SystemSettingDraft::query()->where('setting_definition_id', $definition->id)->exists()) {
            throw new SettingException(
                'DECORATION_PAGES_DRAFT_PENDING',
                '页面装修还有未发布的草稿，请先在「页面装修」中发布后再启用方案。',
                409,
            );
        }
    }
}
