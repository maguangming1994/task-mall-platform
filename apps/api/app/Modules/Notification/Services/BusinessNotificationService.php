<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Models\NotificationBusinessEvent;
use App\Modules\Order\Models\Order;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Support\TaskClaimState;
use Illuminate\Support\Str;

class BusinessNotificationService
{
    public function __construct(
        private readonly NotificationSceneRegistry $registry,
        private readonly NotificationSceneConfigService $configs,
        private readonly NotificationService $notifications,
        private readonly WechatMiniTemplateData $wechatTemplates,
    ) {}

    public function dispatch(NotificationBusinessEvent $event): ?Notification
    {
        if (! $this->stillRelevant($event)) {
            return null;
        }
        $definition = $this->registry->get($event->scene_code);
        $configuration = $this->configs->configuration($event->scene_code);
        if (! $configuration->enabled) {
            return null;
        }
        $user = User::query()->findOrFail($event->user_id);
        $variables = $this->variables($definition['allowed_variables'], $event->variables ?? []);
        $inApp = $this->configs->publishedTemplate($event->scene_code, 'in_app');
        $title = $this->render((string) ($inApp['subject'] ?? $definition['default_subject']), $variables);
        $body = $this->render((string) ($inApp['body'] ?? $definition['default_body']), $variables);
        $channelPayloads = [];

        foreach (array_diff($configuration->channels, ['in_app']) as $channel) {
            $template = $this->configs->publishedTemplate($event->scene_code, $channel);
            if (! $this->configs->templateReady($channel, $template)) {
                $channelPayloads[$channel] = [
                    'configured' => false,
                    'config_error' => '该场景的渠道模板尚未发布或配置不完整。',
                ];

                continue;
            }
            $channelPayloads[$channel] = [
                'configured' => true,
                'template_id' => $template['template_id'],
                'template_version_no' => $template['version_no'],
                'provider_template_id' => $template['provider_template_id'],
                'subject' => $this->render((string) $template['subject'], $variables),
                'body' => $this->render((string) $template['body'], $variables),
                'variables' => $variables,
            ];
            if ($channel === 'wechat_mini') {
                $channelPayloads[$channel] += [
                    'provider' => 'wechat_mini_subscription',
                    'subscription_required' => true,
                    'provider_data' => $this->wechatTemplates->render((string) $template['body'], $variables),
                    'page' => $this->render((string) $definition['deep_link'], $variables),
                ];
            }
        }

        return $this->notifications->create(
            $user,
            $definition['category'],
            $event->scene_code,
            Str::limit($title, 160, ''),
            $body,
            'business-notification-event:'.$event->public_id,
            (bool) $definition['essential'],
            array_values(array_diff($configuration->channels, ['in_app'])),
            $event->data ?? [],
            $channelPayloads,
        );
    }

    /** @param array<int,string> $allowed
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    private function variables(array $allowed, array $values): array
    {
        $result = [];
        foreach ($allowed as $name) {
            $value = $values[$name] ?? '';
            $result[$name] = is_scalar($value) || $value === null ? trim((string) $value) : '';
        }

        return $result;
    }

    /** @param array<string,string> $variables */
    private function render(string $template, array $variables): string
    {
        return strtr($template, collect($variables)->mapWithKeys(fn (string $value, string $key): array => ['{{'.$key.'}}' => $value])->all());
    }

    private function stillRelevant(NotificationBusinessEvent $event): bool
    {
        if (in_array($event->scene_code, ['task_claimed', 'task_review_started', 'task_progress_started', 'task_ready_to_submit'], true)) {
            $claimId = (string) data_get($event->data, 'task_claim_id', '');
            $query = TaskClaim::query()->where('public_id', $claimId);
            $expected = match ($event->scene_code) {
                'task_claimed' => ['status', 'claimed'],
                'task_review_started' => ['status', 'reviewing'],
                'task_progress_started' => ['execution_status', TaskClaimState::EXECUTION_IN_PROGRESS],
                default => ['execution_status', TaskClaimState::EXECUTION_READY_TO_SUBMIT],
            };

            return $claimId !== '' && $query->where($expected[0], $expected[1])->exists();
        }
        if ($event->scene_code === 'order_fulfillment_updated') {
            $orderId = (string) data_get($event->data, 'order_id', '');

            return $orderId !== '' && Order::query()
                ->where('public_id', $orderId)
                ->where('status', 'pending_fulfillment')
                ->exists();
        }

        return true;
    }
}
