<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Notification\Exceptions\PermanentNotificationDeliveryException;
use App\Modules\Notification\Exceptions\SkippedNotificationDeliveryException;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Models\NotificationCampaign;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly WechatSubscriptionGrantService $wechatGrants,
        private readonly WechatMiniSubscriptionSender $wechatMini,
        private readonly WechatOfficialAccountSubscriptionSender $wechatOa,
        private readonly NotificationSceneRegistry $sceneRegistry,
        private readonly WechatMiniTemplateData $wechatTemplates,
        private readonly ReleaseControlService $releases,
    ) {}

    public function create(User $user, string $category, string $scene, string $title, string $body, string $eventKey, bool $essential = false, array $channels = ['in_app'], array $data = [], array $channelPayloads = []): Notification
    {
        $hash = hash('sha256', $eventKey);
        if ($notification = Notification::query()->where('event_key_hash', $hash)->first()) {
            return $notification;
        }

        $releaseContext = $this->externalNotificationContext();
        $externalNotificationsEnabled = $this->releases->enabled('external_notifications', $releaseContext['subject'], $releaseContext['platform']);

        return DB::transaction(function () use ($user, $category, $scene, $title, $body, $hash, $essential, $channels, $data, $channelPayloads, $releaseContext, $externalNotificationsEnabled) {
            $preference = $this->preference($user);
            $notification = Notification::query()->firstOrCreate(
                ['event_key_hash' => $hash],
                ['user_id' => $user->id, 'category' => $category, 'scene_code' => $scene, 'title' => $title, 'body' => $body, 'data' => $data, 'essential' => $essential],
            );
            if (! $notification->wasRecentlyCreated) {
                return $notification;
            }
            foreach (array_unique(['in_app', ...$channels]) as $channel) {
                $channelPayload = $channelPayloads[$channel] ?? [];
                $payload = ['scene' => $scene, 'title' => $title, 'body' => $body, 'data' => $data, ...$channelPayload];
                if ($channel !== 'in_app') {
                    $payload['_external_notification_subject'] = $releaseContext['subject'];
                    $payload['_external_notification_platform'] = $releaseContext['platform'];
                }
                $wechatReservation = null;
                $errorCode = null;
                $errorMessage = null;
                if ($channel !== 'in_app' && ! $externalNotificationsEnabled) {
                    $status = 'skipped';
                    $errorCode = 'EXTERNAL_NOTIFICATIONS_DISABLED';
                    $errorMessage = '外部通知当前未开放，本次投递已终止。';
                } elseif (! $this->channelEnabled($channel, $preference)) {
                    $status = 'skipped';
                    $errorCode = 'CHANNEL_DISABLED';
                    $errorMessage = '用户已关闭该外部通知渠道。';
                } elseif (($channelPayload['configured'] ?? true) === false) {
                    $status = 'skipped';
                    $errorCode = 'CHANNEL_TEMPLATE_NOT_READY';
                    $errorMessage = (string) ($channelPayload['config_error'] ?? '渠道模板配置不完整。');
                } elseif ($channel === 'wechat_mini' && ($channelPayload['subscription_required'] ?? false)) {
                    $wechatReservation = $this->wechatGrants->reserve($user, (string) ($channelPayload['provider_template_id'] ?? ''));
                    if ($wechatReservation === null) {
                        $status = 'skipped';
                        $errorCode = 'WECHAT_SUBSCRIPTION_NOT_GRANTED';
                        $errorMessage = '用户当前没有可用的微信订阅授权。';
                    } else {
                        $payload['wechat_identity_id'] = $wechatReservation['identity_id'];
                        $payload['wechat_app_id_hash'] = $wechatReservation['app_id_hash'];
                        $status = $this->inQuietHours($preference) && ! $essential ? 'scheduled' : 'pending';
                    }
                } else {
                    $status = $channel === 'in_app' ? 'sent' : ($this->inQuietHours($preference) && ! $essential ? 'scheduled' : 'pending');
                }
                DB::table('notification_deliveries')->insert([
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'status' => $status,
                    'payload_snapshot' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'next_retry_at' => $status === 'scheduled' ? $this->quietHoursEnd($preference) : null,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'sent_at' => $status === 'sent' ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $notification;
        });
    }

    public function deliverPending(int $limit = 100): int
    {
        DB::table('notification_deliveries')->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(10))->update(['status' => 'retrying', 'next_retry_at' => now(), 'updated_at' => now()]);
        $count = 0;
        DB::table('notification_deliveries')->whereIn('status', ['pending', 'retrying', 'scheduled'])->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->orderBy('id')->limit($limit)->get()->each(function ($delivery) use (&$count): void {
            $count += $this->deliver((int) $delivery->id) ? 1 : 0;
        });

        return $count;
    }

    public function createCampaign(AdminUser $admin, array $data, string $key): NotificationCampaign
    {
        $hash = hash('sha256', $key);
        if ($campaign = NotificationCampaign::query()->where('idempotency_key_hash', $hash)->first()) {
            return $campaign;
        }
        $scheduled = empty($data['scheduled_at']) ? now() : CarbonImmutable::parse($data['scheduled_at']);

        return NotificationCampaign::query()->create(['name' => $data['name'], 'title' => $data['title'], 'body' => $data['body'], 'channels' => $data['channels'], 'status' => $scheduled->isFuture() ? 'scheduled' : 'ready', 'idempotency_key_hash' => $hash, 'created_by_admin_id' => $admin->id, 'scheduled_at' => $scheduled]);
    }

    public function runCampaigns(int $limit = 10): int
    {
        $processed = 0;
        NotificationCampaign::query()->whereIn('status', ['ready', 'scheduled'])->where('scheduled_at', '<=', now())->limit($limit)->get()->each(function (NotificationCampaign $campaign) use (&$processed): void {
            DB::transaction(function () use ($campaign): void {
                $locked = NotificationCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
                if (! in_array($locked->status, ['ready', 'scheduled'], true)) {
                    return;
                }
                $locked->update(['status' => 'sending']);
                $recipients = User::query()->where('status', 'active')->whereHas('notificationPreference', fn ($query) => $query->where('marketing_subscribed', true))->get();
                foreach ($recipients as $user) {
                    $this->create($user, 'system', 'marketing_campaign', $locked->title, $locked->body, 'campaign:'.$locked->public_id.':'.$user->public_id, false, $locked->channels, ['campaign_id' => $locked->public_id]);
                }
                $locked->update(['status' => 'completed', 'recipient_count' => $recipients->count(), 'sent_count' => $recipients->count(), 'completed_at' => now()]);
            });
            $processed++;
        });

        return $processed;
    }

    public function cancelCampaign(NotificationCampaign $campaign): NotificationCampaign
    {
        return DB::transaction(function () use ($campaign): NotificationCampaign {
            $locked = NotificationCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if (! in_array($locked->status, ['draft', 'ready', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => '只有草稿、待发送或计划中的触达可以取消。']);
            }

            $locked->update(['status' => 'cancelled']);

            return $locked->fresh();
        });
    }

    public function retryDelivery(int $deliveryId): object
    {
        return DB::transaction(function () use ($deliveryId): object {
            $delivery = DB::table('notification_deliveries')->lockForUpdate()->find($deliveryId);
            if (! $delivery) {
                throw ValidationException::withMessages(['delivery' => '通知投递记录不存在。']);
            }
            if ($delivery->status === 'retrying') {
                return $delivery;
            }
            if ($delivery->status !== 'failed') {
                throw ValidationException::withMessages(['status' => '只有发送失败的通知可以重新投递。']);
            }

            DB::table('notification_deliveries')->where('id', $deliveryId)->update([
                'status' => 'retrying',
                'retry_count' => 0,
                'next_retry_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

            return DB::table('notification_deliveries')->find($deliveryId);
        });
    }

    public function saveTemplate(AdminUser $admin, array $data): NotificationTemplate
    {
        $variables = array_values(array_unique($data['allowed_variables']));
        if ($this->sceneRegistry->has($data['scene_code'])) {
            $definition = $this->sceneRegistry->get($data['scene_code']);
            if (array_diff($variables, $definition['allowed_variables'])) {
                throw ValidationException::withMessages(['allowed_variables' => '变量白名单包含当前业务场景不支持的字段。']);
            }
        }
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $data['subject'].' '.$data['body'], $matches);
        if (array_diff(array_unique($matches[1]), $variables)) {
            throw ValidationException::withMessages(['body' => '模板使用了未加入白名单的变量。']);
        }
        if (preg_match('/\{\{[^}]+\}\}/', preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $data['subject'].' '.$data['body']))) {
            throw ValidationException::withMessages(['body' => '模板变量格式无效。']);
        }
        if (in_array($data['channel'], ['wechat_mini', 'wechat_oa'], true) && $data['publish']) {
            if (blank($data['provider_template_id'] ?? null)) {
                throw ValidationException::withMessages(['provider_template_id' => '发布微信订阅模板前必须填写微信模板 ID。']);
            }
            $this->wechatTemplates->decode($data['body']);
        }

        return DB::transaction(function () use ($admin, $data, $variables) {
            $template = NotificationTemplate::query()->firstOrCreate(['scene_code' => $data['scene_code'], 'channel' => $data['channel'], 'locale' => 'zh-CN'], ['created_by_admin_id' => $admin->id]);
            $template = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $version = $template->current_version_no + 1;
            DB::table('notification_template_versions')->insert(['notification_template_id' => $template->id, 'version_no' => $version, 'subject' => $data['subject'], 'body' => $data['body'], 'allowed_variables' => json_encode($variables), 'provider_template_id' => $data['provider_template_id'] ?? null, 'status' => $data['publish'] ? 'published' : 'draft', 'created_by_admin_id' => $admin->id, 'published_at' => $data['publish'] ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            $template->update(['current_version_no' => $version, 'status' => $data['publish'] ? 'published' : 'draft']);

            return $template->fresh();
        });
    }

    public function preference(User $user): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(['user_id' => $user->id], ['quiet_hours_start' => $this->settings->read('notifications.quiet_hours_start')['value'], 'quiet_hours_end' => $this->settings->read('notifications.quiet_hours_end')['value']]);
    }

    private function deliver(int $id): bool
    {
        $delivery = DB::transaction(function () use ($id) {
            $delivery = DB::table('notification_deliveries')->lockForUpdate()->find($id);
            if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying', 'scheduled'], true)) {
                return null;
            }
            DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'processing', 'updated_at' => now()]);

            return $delivery;
        });
        if ($delivery === null) {
            return false;
        }

        try {
            $payload = json_decode($delivery->payload_snapshot, true) ?: [];
            $releaseContext = $this->externalNotificationContext(
                isset($payload['_external_notification_subject']) ? (string) $payload['_external_notification_subject'] : null,
                isset($payload['_external_notification_platform']) ? (string) $payload['_external_notification_platform'] : null,
            );
            if ($delivery->channel !== 'in_app' && ! $this->releases->enabled('external_notifications', $releaseContext['subject'], $releaseContext['platform'])) {
                throw new SkippedNotificationDeliveryException('EXTERNAL_NOTIFICATIONS_DISABLED', '外部通知当前未开放，本次投递已终止。');
            }
            unset($payload['_external_notification_subject'], $payload['_external_notification_platform']);
            $notification = Notification::query()->findOrFail($delivery->notification_id);
            if ($delivery->channel === 'wechat_mini' && ($payload['provider'] ?? null) === 'wechat_mini_subscription') {
                $providerId = $this->wechatMini->send(User::query()->findOrFail($notification->user_id), $payload);
            } elseif ($delivery->channel === 'wechat_oa' && ($payload['provider'] ?? null) === 'wechat_official_account_subscription') {
                $providerId = $this->wechatOa->send(User::query()->findOrFail($notification->user_id), $payload);
            } elseif (config('notifications.driver') === 'local') {
                $providerId = 'local-'.Str::ulid();
            } else {
                $response = Http::withToken((string) config('notifications.webhook.token'))
                    ->timeout((int) config('notifications.webhook.timeout_seconds'))
                    ->post((string) config('notifications.webhook.endpoint'), [
                        'delivery_id' => $delivery->id,
                        'notification_id' => $notification->public_id,
                        'channel' => $delivery->channel,
                        'payload' => $payload,
                    ]);
                $response->throw();
                $providerId = (string) ($response->json('message_id') ?: Str::ulid());
            }
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'processing')->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now(), 'next_retry_at' => null, 'error_code' => null, 'error_message' => null, 'updated_at' => now()]);

            return true;
        } catch (SkippedNotificationDeliveryException $exception) {
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'processing')->update(['status' => 'skipped', 'error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500), 'next_retry_at' => null, 'updated_at' => now()]);

            return false;
        } catch (PermanentNotificationDeliveryException $exception) {
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'processing')->update(['status' => 'failed', 'retry_count' => $delivery->retry_count + 1, 'error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500), 'next_retry_at' => null, 'updated_at' => now()]);

            return false;
        } catch (Throwable $exception) {
            $retry = $delivery->retry_count + 1;
            $max = (int) $this->settings->read('notifications.external_retry_count')['value'];
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'processing')->update(['status' => $retry >= $max ? 'failed' : 'retrying', 'retry_count' => $retry, 'next_retry_at' => $retry >= $max ? null : now()->addMinutes(2 ** $retry), 'error_code' => class_basename($exception), 'error_message' => Str::limit($exception->getMessage(), 500), 'updated_at' => now()]);

            return false;
        }
    }

    private function externalNotificationContext(?string $subject = null, ?string $platform = null): array
    {
        $request = app('request');

        return [
            'subject' => $subject ?? (string) ($request->header('X-Device-Id') ?: 'anonymous'),
            'platform' => $platform ?? $request->header('X-Client-Platform'),
        ];
    }

    private function channelEnabled(string $channel, NotificationPreference $preference): bool
    {
        return match ($channel) {
            'in_app' => true,
            'wechat', 'wechat_mini', 'wechat_oa' => (bool) $preference->wechat_enabled,
            'sms' => (bool) $preference->sms_enabled,
            default => false,
        };
    }

    private function inQuietHours(NotificationPreference $preference): bool
    {
        $time = now()->format('H:i');
        $start = substr((string) $preference->quiet_hours_start, 0, 5);
        $end = substr((string) $preference->quiet_hours_end, 0, 5);

        return $start <= $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    private function quietHoursEnd(NotificationPreference $preference)
    {
        $end = now()->setTimeFromTimeString((string) $preference->quiet_hours_end);

        return $end->isFuture() ? $end : $end->addDay();
    }
}
