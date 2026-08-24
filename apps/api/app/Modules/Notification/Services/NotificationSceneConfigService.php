<?php

namespace App\Modules\Notification\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Notification\Models\NotificationSceneConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationSceneConfigService
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $publishedTemplateCache = null;

    public function __construct(
        private readonly NotificationSceneRegistry $registry,
        private readonly WechatMiniTemplateData $wechatTemplates,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        $configs = NotificationSceneConfig::query()->get()->keyBy('scene_code');

        return collect($this->registry->all())
            ->map(function (array $definition, string $sceneCode) use ($configs): array {
                $config = $configs->get($sceneCode) ?? $this->createDefault($sceneCode, $definition);
                $templates = [];
                foreach ($definition['allowed_channels'] as $channel) {
                    $snapshot = $this->publishedTemplate($sceneCode, $channel);
                    $templates[$channel] = [
                        'configured' => $snapshot !== null,
                        'ready' => $this->templateReady($channel, $snapshot),
                        'template_id' => $snapshot['template_id'] ?? null,
                        'version_no' => $snapshot['version_no'] ?? null,
                        'provider_template_id' => $snapshot['provider_template_id'] ?? null,
                    ];
                }

                return [
                    'scene_code' => $sceneCode,
                    ...collect($definition)->except(['default_subject', 'default_body'])->all(),
                    'enabled' => $config->enabled,
                    'channels' => $config->channels,
                    'lock_version' => $config->lock_version,
                    'default_template' => ['subject' => $definition['default_subject'], 'body' => $definition['default_body']],
                    'templates' => $templates,
                    'updated_at' => $config->updated_at?->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $scene): string => $scene['domain'].':'.str_pad((string) $scene['sort'], 4, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    public function update(string $sceneCode, array $data, AdminUser $admin): array
    {
        $definition = $this->registry->get($sceneCode);
        $channels = array_values(array_unique($data['channels']));
        if (! in_array('in_app', $channels, true)) {
            throw ValidationException::withMessages(['channels' => '业务状态通知必须保留站内消息。']);
        }
        if (array_diff($channels, $definition['allowed_channels'])) {
            throw ValidationException::withMessages(['channels' => '包含当前场景不支持的通知渠道。']);
        }
        if ($data['enabled']) {
            foreach (array_diff($channels, ['in_app']) as $channel) {
                if (! $this->templateReady($channel, $this->publishedTemplate($sceneCode, $channel))) {
                    throw ValidationException::withMessages(['channels' => $this->channelName($channel).'模板尚未发布或配置不完整。']);
                }
            }
        }

        return DB::transaction(function () use ($sceneCode, $data, $channels, $admin): array {
            $config = NotificationSceneConfig::query()->where('scene_code', $sceneCode)->lockForUpdate()->first();
            $config ??= $this->createDefault($sceneCode, $this->registry->get($sceneCode));
            if ($config->lock_version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => '通知规则已被其他管理员更新，请刷新后重试。']);
            }
            $config->update([
                'enabled' => (bool) $data['enabled'],
                'channels' => $channels,
                'lock_version' => $config->lock_version + 1,
                'updated_by_admin_id' => $admin->id,
            ]);

            return collect($this->catalog())->firstWhere('scene_code', $sceneCode);
        });
    }

    public function configuration(string $sceneCode): NotificationSceneConfig
    {
        $definition = $this->registry->get($sceneCode);

        return NotificationSceneConfig::query()->firstOrCreate(
            ['scene_code' => $sceneCode],
            ['enabled' => true, 'channels' => $definition['default_channels'], 'lock_version' => 1],
        );
    }

    /** @return array<string,mixed>|null */
    public function publishedTemplate(string $sceneCode, string $channel): ?array
    {
        if ($this->publishedTemplateCache === null) {
            $this->publishedTemplateCache = DB::table('notification_templates as templates')
                ->join('notification_template_versions as versions', function ($join): void {
                    $join->on('versions.notification_template_id', '=', 'templates.id')
                        ->on('versions.version_no', '=', 'templates.current_version_no');
                })
                ->where('templates.locale', 'zh-CN')
                ->where('templates.status', 'published')
                ->where('versions.status', 'published')
                ->get([
                    'templates.scene_code', 'templates.channel', 'templates.public_id as template_id',
                    'versions.version_no', 'versions.subject', 'versions.body',
                    'versions.allowed_variables', 'versions.provider_template_id',
                ])
                ->mapWithKeys(fn (object $row): array => [
                    $row->scene_code.':'.$row->channel => [
                        'template_id' => $row->template_id,
                        'version_no' => (int) $row->version_no,
                        'subject' => $row->subject,
                        'body' => $row->body,
                        'allowed_variables' => json_decode($row->allowed_variables, true) ?: [],
                        'provider_template_id' => $row->provider_template_id,
                    ],
                ])->all();
        }

        return $this->publishedTemplateCache[$sceneCode.':'.$channel] ?? null;
    }

    /** @param array<string,mixed>|null $template */
    public function templateReady(string $channel, ?array $template): bool
    {
        if ($template === null) {
            return false;
        }
        if ($channel === 'in_app') {
            return true;
        }
        if (blank($template['provider_template_id'] ?? null)) {
            return false;
        }
        if ($channel === 'wechat_mini') {
            return $this->wechatTemplates->isValid((string) $template['body']);
        }

        return true;
    }

    /** @param array<string,mixed> $definition */
    private function createDefault(string $sceneCode, array $definition): NotificationSceneConfig
    {
        return NotificationSceneConfig::query()->firstOrCreate(
            ['scene_code' => $sceneCode],
            ['enabled' => true, 'channels' => $definition['default_channels'], 'lock_version' => 1],
        );
    }

    private function channelName(string $channel): string
    {
        return ['wechat_mini' => '微信小程序订阅消息', 'sms' => '短信'][$channel] ?? '外部渠道';
    }
}
