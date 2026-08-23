<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Models\NotificationCampaign;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function create(User $user, string $category, string $scene, string $title, string $body, string $eventKey, bool $essential = false, array $channels = ['in_app'], array $data = []): Notification
    {
        $hash = hash('sha256', $eventKey);
        if ($notification = Notification::query()->where('event_key_hash', $hash)->first()) {
            return $notification;
        }

        return DB::transaction(function () use ($user, $category, $scene, $title, $body, $hash, $essential, $channels, $data) {
            $preference = $this->preference($user);
            $notification = Notification::query()->create(['user_id' => $user->id, 'category' => $category, 'scene_code' => $scene, 'title' => $title, 'body' => $body, 'data' => $data, 'essential' => $essential, 'event_key_hash' => $hash]);
            foreach (array_unique(['in_app', ...$channels]) as $channel) {
                $enabled = $channel === 'in_app' || $essential || (bool) $preference->{$channel.'_enabled'};
                $status = $enabled ? ($channel === 'in_app' ? 'sent' : ($this->inQuietHours($preference) && ! $essential ? 'scheduled' : 'pending')) : 'skipped';
                DB::table('notification_deliveries')->insert(['notification_id' => $notification->id, 'channel' => $channel, 'status' => $status, 'payload_snapshot' => json_encode(['scene' => $scene, 'title' => $title, 'body' => $body, 'data' => $data], JSON_UNESCAPED_UNICODE), 'next_retry_at' => $status === 'scheduled' ? $this->quietHoursEnd($preference) : null, 'sent_at' => $status === 'sent' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $notification;
        });
    }

    public function deliverPending(int $limit = 100): int
    {
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
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $data['subject'].' '.$data['body'], $matches);
        if (array_diff(array_unique($matches[1]), $variables)) {
            throw ValidationException::withMessages(['body' => '模板使用了未加入白名单的变量。']);
        }
        if (preg_match('/\{\{[^}]+\}\}/', preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $data['subject'].' '.$data['body']))) {
            throw ValidationException::withMessages(['body' => '模板变量格式无效。']);
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
        return DB::transaction(function () use ($id) {
            $delivery = DB::table('notification_deliveries')->lockForUpdate()->find($id);
            if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying', 'scheduled'], true)) {
                return false;
            }
            try {
                if (config('notifications.driver') === 'local') {
                    $providerId = 'local-'.Str::ulid();
                } else {
                    $response = Http::withToken((string) config('notifications.webhook.token'))->timeout((int) config('notifications.webhook.timeout_seconds'))->post((string) config('notifications.webhook.endpoint'), ['channel' => $delivery->channel, 'payload' => json_decode($delivery->payload_snapshot, true)]);
                    $response->throw();
                    $providerId = (string) ($response->json('message_id') ?: Str::ulid());
                }
                DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now(), 'next_retry_at' => null, 'updated_at' => now()]);

                return true;
            } catch (Throwable $exception) {
                $retry = $delivery->retry_count + 1;
                $max = (int) $this->settings->read('notifications.external_retry_count')['value'];
                DB::table('notification_deliveries')->where('id', $id)->update(['status' => $retry >= $max ? 'failed' : 'retrying', 'retry_count' => $retry, 'next_retry_at' => $retry >= $max ? null : now()->addMinutes(2 ** $retry), 'error_code' => class_basename($exception), 'error_message' => Str::limit($exception->getMessage(), 500), 'updated_at' => now()]);

                return false;
            }
        });
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
