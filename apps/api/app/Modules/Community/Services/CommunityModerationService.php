<?php

namespace App\Modules\Community\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ContentAppeal;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Models\UserRestriction;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunityModerationService
{
    public function __construct(private readonly SystemSettingService $settings, private readonly AssetLedgerService $assets, private readonly NotificationService $notifications) {}

    public function report(User $user, string $type, string $publicId, array $data, string $key): ContentReport
    {
        [$content] = $this->content($type, $publicId);
        abort_if($content->user_id === $user->id, 422);
        $hash = hash('sha256', $key);
        if ($report = ContentReport::query()->where('idempotency_key_hash', $hash)->first()) {
            abort_unless($report->reporter_user_id === $user->id, 404);

            return $report;
        }
        $evidence = $this->validateEvidence($user, $data['evidence_file_ids'] ?? []);
        $existing = ContentReport::query()->where(['reporter_user_id' => $user->id, 'content_type' => $type, 'content_id' => $content->id])->first();
        if ($existing) {
            $existing->update(['reason_code' => $data['reason_code'], 'description' => $data['description'] ?? $existing->description, 'evidence_file_ids' => $evidence ?: $existing->evidence_file_ids, 'status' => 'submitted', 'resolved_at' => null]);

            return $existing->fresh();
        }

        return ContentReport::query()->create(['reporter_user_id' => $user->id, 'content_type' => $type, 'content_id' => $content->id, 'reason_code' => $data['reason_code'], 'description' => $data['description'] ?? null, 'evidence_file_ids' => $evidence, 'status' => 'submitted', 'idempotency_key_hash' => $hash]);
    }

    public function moderate(AdminUser $admin, string $type, string $publicId, array $data, string $key): ModerationAction
    {
        $hash = hash('sha256', $key);
        if ($action = ModerationAction::query()->where('idempotency_key_hash', $hash)->first()) {
            return $action;
        }
        [$content, $model] = $this->content($type, $publicId);

        return DB::transaction(function () use ($admin, $type, $content, $model, $data, $hash) {
            $locked = $model::query()->lockForUpdate()->findOrFail($content->id);
            $previous = $locked->status;
            $decision = $data['decision'];
            $rewardRevoked = false;
            if (in_array($decision, ['hide', 'delete'], true)) {
                if ($locked->status === 'published' && $type === 'comment') {
                    CommunityPost::query()->whereKey($locked->community_post_id)->where('comment_count', '>', 0)->decrement('comment_count');
                }
                $locked->update(['status' => $decision === 'hide' ? 'hidden' : 'deleted', 'reviewed_by_admin_id' => $admin->id, 'review_reason_code' => $data['reason_code'], 'review_note' => $data['note']]);
                if ($locked->reward_granted && (bool) $this->value('community.revoke_reward_on_violation')) {
                    $points = (int) $this->value('points.source_reward_amounts')[$type === 'post' ? 'post' : 'comment'];
                    $author = User::query()->findOrFail($locked->user_id);
                    $this->assets->revokePointsWithDebt($author, $points, $type, $locked->public_id, '社区内容违规，撤销对应积分奖励。', 'community-moderation:'.$type.':'.$locked->public_id.':'.$hash, $admin);
                    $locked->update(['reward_granted' => false]);
                    $rewardRevoked = true;
                }
            }
            $days = (int) $this->value('community.appeal_days');
            $action = ModerationAction::query()->create(['content_type' => $type, 'content_id' => $locked->id, 'content_author_user_id' => $locked->user_id, 'action_type' => $decision, 'reason_code' => $data['reason_code'], 'note' => $data['note'], 'previous_status' => $previous, 'reward_revoked' => $rewardRevoked, 'status' => 'effective', 'admin_user_id' => $admin->id, 'idempotency_key_hash' => $hash, 'appeal_deadline_at' => now()->addDays($days)]);
            foreach (array_unique($data['restrictions'] ?? []) as $restriction) {
                UserRestriction::query()->create(['user_id' => $locked->user_id, 'moderation_action_id' => $action->id, 'restriction_type' => $restriction, 'reason_code' => $data['reason_code'], 'status' => 'active', 'starts_at' => now(), 'ends_at' => empty($data['restriction_days']) ? null : now()->addDays((int) $data['restriction_days'])]);
            }
            ContentReport::query()->where(['content_type' => $type, 'content_id' => $locked->id])->where('status', 'submitted')->update(['status' => 'resolved', 'resolved_at' => now()]);
            if ($type !== 'post') {
                $this->notifications->create(User::query()->findOrFail($locked->user_id), 'community', 'content_moderated', '社区内容处置通知', $data['note'], 'content-moderation:'.$action->public_id, true);
            }

            return $action;
        });
    }

    public function appeal(User $user, ModerationAction $action, array $data, string $key): ContentAppeal
    {
        abort_unless($action->content_author_user_id === $user->id, 404);
        if ($action->status !== 'effective' || ! in_array($action->action_type, ['hide', 'delete'], true) || $action->appeal_deadline_at?->isPast()) {
            throw ValidationException::withMessages(['action' => '该处置当前不可申诉或已超过申诉期限。']);
        }
        $hash = hash('sha256', $key);
        if ($appeal = ContentAppeal::query()->where('idempotency_key_hash', $hash)->first()) {
            return $appeal;
        }
        if (ContentAppeal::query()->where('moderation_action_id', $action->id)->exists()) {
            throw ValidationException::withMessages(['action' => '同一处置只允许申诉一次。']);
        }
        $evidence = $this->validateEvidence($user, $data['evidence_file_ids'] ?? []);

        return ContentAppeal::query()->create(['moderation_action_id' => $action->id, 'user_id' => $user->id, 'reason' => $data['reason'], 'evidence_file_ids' => $evidence, 'status' => 'submitted', 'idempotency_key_hash' => $hash]);
    }

    public function reviewAppeal(AdminUser $admin, ContentAppeal $appeal, array $data): ContentAppeal
    {
        return DB::transaction(function () use ($admin, $appeal, $data) {
            $locked = ContentAppeal::query()->lockForUpdate()->findOrFail($appeal->id);
            if ($locked->status !== 'submitted') {
                return $locked;
            }
            $action = ModerationAction::query()->lockForUpdate()->findOrFail($locked->moderation_action_id);
            if ($action->admin_user_id === $admin->id) {
                throw ValidationException::withMessages(['reviewer' => '申诉必须由不同于原处置人的审核员复核。']);
            }
            if ($data['decision'] === 'approve') {
                [$content, $model] = $this->contentById($action->content_type, $action->content_id);
                $restore = $action->previous_status === 'published' ? 'published' : $action->previous_status;
                $content->update(['status' => $restore]);
                if ($restore === 'published' && $action->content_type === 'comment') {
                    CommunityPost::query()->whereKey($content->community_post_id)->increment('comment_count');
                }
                if ($action->reward_revoked) {
                    $points = (int) $this->value('points.source_reward_amounts')[$action->content_type === 'post' ? 'post' : 'comment'];
                    $this->assets->grantPoints(User::query()->findOrFail($content->user_id), $points, $action->content_type, $content->public_id, '社区申诉通过，恢复原积分奖励。', 'community-appeal-restore:'.$locked->public_id, $admin);
                    $content->update(['reward_granted' => true]);
                }
                UserRestriction::query()->where('moderation_action_id', $action->id)->where('status', 'active')->update(['status' => 'revoked', 'revoked_by_admin_id' => $admin->id, 'revoked_at' => now()]);
                $action->update(['status' => 'reversed', 'reversed_at' => now()]);
                $locked->update(['status' => 'approved', 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'reviewed_at' => now()]);
            } else {
                $locked->update(['status' => 'rejected', 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'reviewed_at' => now()]);
            }
            if ($action->content_type !== 'post') {
                $this->notifications->create(User::query()->findOrFail($locked->user_id), 'community', 'content_appeal_reviewed', '社区申诉结果', $data['note'], 'content-appeal-review:'.$locked->public_id, true);
            }

            return $locked->fresh();
        });
    }

    private function content(string $type, string $publicId): array
    {
        $model = $type === 'post' ? CommunityPost::class : CommunityComment::class;
        $content = $model::query()->where('public_id', $publicId)->firstOrFail();

        return [$content, $model];
    }

    private function contentById(string $type, int $id): array
    {
        $model = $type === 'post' ? CommunityPost::class : CommunityComment::class;

        return [$model::query()->findOrFail($id), $model];
    }

    private function validateEvidence(User $user, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $files = UploadedFile::query()->whereIn('public_id', $ids)->get();
        if ($files->count() !== count($ids) || $files->contains(fn (UploadedFile $file) => $file->owner_user_id !== $user->id || $file->purpose !== 'community' || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['evidence_file_ids' => '举报或申诉凭证不存在、未通过安全扫描或不属于当前用户。']);
        }

        return $ids;
    }

    private function value(string $key): mixed
    {
        return $this->settings->read($key)['value'];
    }
}
