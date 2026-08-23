<?php

namespace App\Modules\Community\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityActivityParticipant;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunityActivityService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $user, array $data, string $idempotencyKey): CommunityActivity
    {
        $hash = hash('sha256', $idempotencyKey);
        $existing = CommunityActivity::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            abort_unless($existing->creator_user_id === $user->id, 404);

            return $existing;
        }

        $this->assertSafe($data['title'].' '.$data['details'].' '.$data['location']);
        $files = $this->validateImages($user, $data['image_file_ids'] ?? []);

        return DB::transaction(function () use ($user, $data, $hash, $files): CommunityActivity {
            $activity = CommunityActivity::query()->create([
                'creator_user_id' => $user->id,
                'category_code' => $data['category_code'],
                'title' => trim($data['title']),
                'details' => trim($data['details']),
                'location' => trim($data['location']),
                'starts_at' => $data['starts_at'],
                'participant_limit' => $data['participant_limit'],
                'participant_count' => 1,
                'status' => 'pending_review',
                'idempotency_key_hash' => $hash,
            ]);
            foreach ($files as $sortOrder => $file) {
                DB::table('community_activity_images')->insert([
                    'community_activity_id' => $activity->id,
                    'uploaded_file_id' => $file->id,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $activity->fresh();
        });
    }

    public function join(User $user, CommunityActivity $activity): CommunityActivity
    {
        return DB::transaction(function () use ($user, $activity): CommunityActivity {
            $locked = CommunityActivity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($locked->status !== 'approved' || $locked->starts_at->isPast()) {
                throw ValidationException::withMessages(['activity' => '该活动当前不可参加。']);
            }
            if ($locked->creator_user_id === $user->id) {
                throw ValidationException::withMessages(['activity' => '活动发起人无需重复参加。']);
            }

            $participant = CommunityActivityParticipant::query()
                ->where('community_activity_id', $locked->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($participant?->status === 'joined') {
                return $locked;
            }
            if ($participant?->status === 'removed') {
                throw ValidationException::withMessages(['activity' => '你已被活动管理员移出，如需恢复请联系平台客服。']);
            }
            if ($locked->participant_count >= $locked->participant_limit) {
                throw ValidationException::withMessages(['activity' => '活动名额已满。']);
            }

            if ($participant === null) {
                CommunityActivityParticipant::query()->create([
                    'community_activity_id' => $locked->id,
                    'user_id' => $user->id,
                    'status' => 'joined',
                    'joined_at' => now(),
                ]);
            } else {
                $participant->update(['status' => 'joined', 'joined_at' => now(), 'left_at' => null]);
            }
            $locked->increment('participant_count');

            return $locked->fresh();
        }, 3);
    }

    public function leave(User $user, CommunityActivity $activity): CommunityActivity
    {
        return DB::transaction(function () use ($user, $activity): CommunityActivity {
            $locked = CommunityActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $participant = CommunityActivityParticipant::query()
                ->where('community_activity_id', $locked->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($participant === null || $participant->status !== 'joined') {
                return $locked;
            }
            if ($locked->starts_at->isPast()) {
                throw ValidationException::withMessages(['activity' => '活动开始后不能退出，请联系发起人。']);
            }

            $participant->update(['status' => 'left', 'left_at' => now()]);
            $locked->update(['participant_count' => max(0, $locked->participant_count - 1)]);

            return $locked->fresh();
        }, 3);
    }

    public function review(AdminUser $admin, CommunityActivity $activity, array $data): CommunityActivity
    {
        return DB::transaction(function () use ($admin, $activity, $data): CommunityActivity {
            $locked = CommunityActivity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($locked->status !== 'pending_review') {
                throw ValidationException::withMessages(['activity' => '当前活动状态不可审核。']);
            }

            $approved = $data['decision'] === 'approve';
            $locked->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by_admin_id' => $admin->id,
                'review_reason_code' => $approved ? null : ($data['reason_code'] ?? 'content_review'),
                'review_note' => $data['note'],
                'reviewed_at' => now(),
            ]);
            $this->audit->record(
                $admin,
                'community.activity.review',
                'community_activity',
                $locked->public_id,
                ['status' => 'pending_review'],
                ['status' => $locked->status, 'reason_code' => $locked->review_reason_code],
                $data['note'],
            );
            $this->notifications->create(
                User::query()->findOrFail($locked->creator_user_id),
                'community',
                $approved ? 'activity_review_approved' : 'activity_review_rejected',
                $approved ? '组团活动审核通过' : '组团活动审核未通过',
                $data['note'],
                'community-activity-review:'.$locked->public_id.':'.($approved ? 'approved' : 'rejected'),
                false,
                ['in_app'],
                ['activity_id' => $locked->public_id],
            );

            return $locked->fresh();
        });
    }

    public function close(AdminUser $admin, CommunityActivity $activity, string $reason): CommunityActivity
    {
        return DB::transaction(function () use ($admin, $activity, $reason): CommunityActivity {
            $locked = CommunityActivity::query()->lockForUpdate()->findOrFail($activity->id);
            if (! in_array($locked->status, ['approved', 'pending_review'], true)) {
                throw ValidationException::withMessages(['activity' => '当前活动状态不可关闭。']);
            }
            $locked->update([
                'status' => 'closed',
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $reason,
                'closed_at' => now(),
            ]);
            $this->audit->record(
                $admin,
                'community.activity.close',
                'community_activity',
                $locked->public_id,
                ['status' => $activity->status],
                ['status' => 'closed'],
                $reason,
            );

            return $locked->fresh();
        });
    }

    public function removeParticipant(AdminUser $admin, CommunityActivity $activity, CommunityActivityParticipant $participant, string $reason): CommunityActivity
    {
        return DB::transaction(function () use ($admin, $activity, $participant, $reason): CommunityActivity {
            $locked = CommunityActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $member = CommunityActivityParticipant::query()->lockForUpdate()->findOrFail($participant->id);
            if ($member->community_activity_id !== $locked->id) {
                abort(404);
            }
            if ($member->status !== 'joined') {
                throw ValidationException::withMessages(['participant' => '该参与人当前不在活动中。']);
            }

            $member->update(['status' => 'removed', 'left_at' => now()]);
            $locked->update(['participant_count' => max(1, $locked->participant_count - 1)]);
            $user = User::query()->findOrFail($member->user_id);
            $this->audit->record(
                $admin,
                'community.activity.participant.remove',
                'community_activity',
                $locked->public_id,
                ['participant_user_id' => $user->public_id, 'status' => 'joined'],
                ['participant_user_id' => $user->public_id, 'status' => 'removed'],
                $reason,
            );
            $this->notifications->create(
                $user,
                'community',
                'activity_participant_removed',
                '你已被移出校园组团',
                "你已被移出“{$locked->title}”。原因：{$reason}",
                'community-activity-participant-removed:'.$locked->public_id.':'.$user->public_id,
                false,
                ['in_app'],
                ['activity_id' => $locked->public_id],
            );

            return $locked->fresh();
        }, 3);
    }

    /** @return array<int, UploadedFile> */
    private function validateImages(User $user, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $files = UploadedFile::query()->whereIn('public_id', $ids)->get()->keyBy('public_id');
        foreach ($ids as $id) {
            $file = $files->get($id);
            if ($file === null || $file->owner_user_id !== $user->id || $file->purpose !== 'community' || $file->status !== 'clean' || ! str_starts_with((string) $file->detected_mime, 'image/')) {
                throw ValidationException::withMessages(['image_file_ids' => '活动图片必须是本人上传且已通过安全扫描的社区图片。']);
            }
        }

        return collect($ids)->map(fn (string $id) => $files->get($id))->all();
    }

    private function assertSafe(string $content): void
    {
        $words = $this->settings->read('community.sensitive_words')['value'] ?? [];
        $matched = collect(is_array($words) ? $words : [])->first(fn ($word) => (string) $word !== '' && str_contains($content, (string) $word));
        if ($matched !== null) {
            throw ValidationException::withMessages(['details' => '活动内容包含平台禁止发布的信息，请修改后重试。']);
        }
    }
}
