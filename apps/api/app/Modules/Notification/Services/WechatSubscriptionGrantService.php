<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Notification\Models\NotificationSceneConfig;
use App\Modules\Notification\Models\WechatSubscriptionGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WechatSubscriptionGrantService
{
    public function __construct(
        private readonly NotificationSceneRegistry $registry,
        private readonly NotificationSceneConfigService $configs,
        private readonly WechatProviderSettingsService $wechatSettings,
    ) {}

    /** @return array{groups:array<int,array<string,mixed>>,configured:bool} */
    public function options(UserIdentity $identity): array
    {
        $appIdHash = $this->assertIdentityScope($identity);
        $groups = [];
        foreach ($this->configuredTemplates() as $templateId => $definition) {
            $context = $definition['context'];
            $groups[$context] ??= [
                'context' => $context,
                'label' => ['task' => '任务进度提醒', 'order' => '订单状态提醒', 'community' => '帖子审核提醒'][$context],
                'template_ids' => [],
                'scenes' => [],
            ];
            $groups[$context]['template_ids'][] = $templateId;
            $groups[$context]['scenes'] = array_values(array_unique([...$groups[$context]['scenes'], ...$definition['scenes']]));
        }

        $grants = WechatSubscriptionGrant::query()
            ->where('user_identity_id', $identity->id)
            ->where('provider_app_id_hash', $appIdHash)
            ->get()
            ->keyBy('provider_template_id');
        foreach ($groups as &$group) {
            $group['template_ids'] = array_values(array_unique($group['template_ids']));
            $group['grants'] = collect($group['template_ids'])->map(function (string $templateId) use ($grants): array {
                $grant = $grants->get($templateId);

                return [
                    'template_id' => $templateId,
                    'status' => $grant?->status ?? 'not_requested',
                    'available_count' => (int) ($grant?->available_count ?? 0),
                ];
            })->values()->all();
        }

        return ['groups' => array_values($groups), 'configured' => $groups !== []];
    }

    /** @param array<int,array{template_id:string,status:string}> $results */
    public function record(UserIdentity $identity, array $results, string $requestId): array
    {
        $appIdHash = $this->assertIdentityScope($identity);
        $allowed = array_keys($this->configuredTemplates());
        if (count($results) !== count(array_unique(array_column($results, 'template_id')))) {
            throw ValidationException::withMessages(['results' => '同一次授权结果不能包含重复模板。']);
        }
        foreach ($results as $result) {
            if (! in_array($result['template_id'], $allowed, true)) {
                throw ValidationException::withMessages(['results' => '包含当前系统未启用的微信订阅模板。']);
            }
        }

        $normalized = collect($results)->sortBy('template_id')->values()->all();
        $resultHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        DB::transaction(function () use ($identity, $appIdHash, $results, $requestId, $resultHash): void {
            $inserted = DB::table('wechat_subscription_requests')->insertOrIgnore([
                'user_id' => $identity->user_id,
                'user_identity_id' => $identity->id,
                'provider_app_id_hash' => $appIdHash,
                'request_id' => $requestId,
                'result_hash' => $resultHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                $existingHash = DB::table('wechat_subscription_requests')
                    ->where('user_identity_id', $identity->id)
                    ->where('provider_app_id_hash', $appIdHash)
                    ->where('request_id', $requestId)
                    ->value('result_hash');
                if (! is_string($existingHash) || ! hash_equals($existingHash, $resultHash)) {
                    throw ValidationException::withMessages(['request_id' => '该订阅登记编号已用于另一组授权结果。']);
                }

                return;
            }
            foreach ($results as $result) {
                $accepted = in_array($result['status'], ['accept', 'acceptWithAudio'], true);
                $banned = $result['status'] === 'ban';
                $grant = WechatSubscriptionGrant::query()
                    ->where('user_identity_id', $identity->id)
                    ->where('provider_app_id_hash', $appIdHash)
                    ->where('provider_template_id', $result['template_id'])
                    ->lockForUpdate()
                    ->first();
                $grant ??= new WechatSubscriptionGrant([
                    'user_id' => $identity->user_id,
                    'user_identity_id' => $identity->id,
                    'provider_app_id_hash' => $appIdHash,
                    'provider_template_id' => $result['template_id'],
                ]);
                $availableCount = (int) $grant->available_count;
                $grant->fill([
                    'status' => $banned ? 'ban' : ($accepted || $availableCount > 0 ? 'accepted' : $result['status']),
                    'available_count' => $banned ? 0 : ($accepted ? $availableCount + 1 : $availableCount),
                    'last_requested_at' => now(),
                    'last_accepted_at' => $accepted ? now() : $grant->last_accepted_at,
                ])->save();
            }
        });

        return $this->options($identity);
    }

    /** @return array{identity_id:int,app_id_hash:string}|null */
    public function reserve(User $user, string $templateId): ?array
    {
        $appIdHash = $this->appIdHash();

        return DB::transaction(function () use ($user, $templateId, $appIdHash): ?array {
            $grant = WechatSubscriptionGrant::query()
                ->where('user_id', $user->id)
                ->where('provider_app_id_hash', $appIdHash)
                ->where('provider_template_id', $templateId)
                ->whereHas('identity', fn ($query) => $query
                    ->where('provider', 'wechat_mini')
                    ->where('user_id', $user->id)
                    ->where('metadata->app_id_hash', $appIdHash))
                ->where('status', 'accepted')
                ->where('available_count', '>', 0)
                ->latest('last_accepted_at')
                ->lockForUpdate()
                ->first();
            if ($grant === null) {
                return null;
            }
            $remaining = $grant->available_count - 1;
            $grant->update([
                'available_count' => $remaining,
                'status' => $remaining > 0 ? 'accepted' : 'consumed',
                'last_consumed_at' => now(),
            ]);

            return ['identity_id' => $grant->user_identity_id, 'app_id_hash' => $appIdHash];
        });
    }

    private function assertIdentityScope(UserIdentity $identity): string
    {
        $appIdHash = $this->appIdHash();
        if ($identity->provider !== 'wechat_mini'
            || ! is_string(data_get($identity->metadata, 'app_id_hash'))
            || ! hash_equals($appIdHash, (string) data_get($identity->metadata, 'app_id_hash'))) {
            throw ValidationException::withMessages(['subscription' => '小程序身份已失效，请重新登录后再授权订阅消息。']);
        }

        return $appIdHash;
    }

    private function appIdHash(): string
    {
        return hash('sha256', (string) $this->wechatSettings->effective('mini')['app_id']);
    }

    /** @return array<string,array{context:string,scenes:array<int,string>}> */
    private function configuredTemplates(): array
    {
        $configs = NotificationSceneConfig::query()->where('enabled', true)->get()->keyBy('scene_code');
        $templates = [];
        foreach ($this->registry->all() as $sceneCode => $definition) {
            $config = $configs->get($sceneCode);
            if ($config === null || ! in_array('wechat_mini', $config->channels, true)) {
                continue;
            }
            $template = $this->configs->publishedTemplate($sceneCode, 'wechat_mini');
            if (! $this->configs->templateReady('wechat_mini', $template)) {
                continue;
            }
            $templateId = (string) $template['provider_template_id'];
            $templates[$templateId] ??= ['context' => $definition['subscription_context'], 'scenes' => []];
            $templates[$templateId]['scenes'][] = $sceneCode;
        }

        return $templates;
    }
}
