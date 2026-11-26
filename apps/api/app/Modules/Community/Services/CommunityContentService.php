<?php

namespace App\Modules\Community\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Community\Models\UserRestriction;
use App\Modules\Notification\Services\NotificationBusinessEventService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityContentService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AssetLedgerService $assets,
        private readonly NotificationService $notifications,
        private readonly NotificationBusinessEventService $notificationEvents,
        private readonly CommunityContentSecurityService $contentSecurity,
    ) {}

    /**
     * @return array{
     *     post: array{
     *         title: array{min_length: int, max_length: int},
     *         content: array{min_length: int, max_length: int}
     *     },
     *     comment: array{min_length: int, max_length: int}
     * }
     */
    public function publishingRules(): array
    {
        $post = $this->postRules();
        $comment = $this->commentRules();

        return [
            'post' => [
                'title' => ['min_length' => $post['title_min'], 'max_length' => $post['title_max']],
                'content' => ['min_length' => $post['content_min'], 'max_length' => $post['content_max']],
            ],
            'comment' => ['min_length' => $comment['min_length'], 'max_length' => $comment['max_length']],
        ];
    }

    /** 发帖审核通过后发放的积分奖励数量（取自 points.source_reward_amounts.post） */
    public function postRewardPoints(): int
    {
        $amounts = $this->value('points.source_reward_amounts');

        return (int) ($amounts['post'] ?? 0);
    }

    public function createPost(User $user, array $data, string $key): CommunityPost
    {
        $this->assertAllowed($user, 'post');
        $hash = hash('sha256', $key);
        if ($post = CommunityPost::withTrashed()->where('idempotency_key_hash', $hash)->first()) {
            abort_unless($post->user_id === $user->id, 404);

            return $post;
        }
        $rules = $this->postRules();
        $this->validatePostText($data, $rules);
        $this->enforceDailyLimit($user, CommunityPost::class, (int) $this->value('community.daily_post_limit'));
        $files = $this->validateAttachments($user, $data['image_file_ids'] ?? [], $data['video_file_id'] ?? null);
        // 内容安全：明确违规直接拒绝，疑似违规即使在自动发布模式下也转人工审核。
        $security = $this->scanPostForReview($user, $data);

        return DB::transaction(function () use ($user, $data, $hash, $rules, $files, $security) {
            $auto = $rules['review_mode'] === 'auto_publish' && ! $security['force_review'];
            $post = CommunityPost::query()->create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'category_code' => $data['category_code'] ?? 'experience',
                'location_name' => $this->normalizeLocation($data['location_name'] ?? null),
                'visibility' => $data['visibility'] ?? 'public',
                'location_latitude' => $data['location_latitude'] ?? null,
                'location_longitude' => $data['location_longitude'] ?? null,
                'status' => $auto ? 'published' : 'pending_review',
                'published_version_no' => $auto ? 1 : null,
                'idempotency_key_hash' => $hash,
                'published_at' => $auto ? now() : null,
            ]);
            $version = CommunityPostVersion::query()->create([
                'community_post_id' => $post->id,
                'version_no' => 1,
                'title' => $data['title'],
                'content' => $data['content'],
                'category_code' => $data['category_code'] ?? 'experience',
                'review_status' => $auto ? 'approved' : 'pending',
                'rule_snapshot' => $rules,
                'reviewed_at' => $auto ? now() : null,
            ]);
            $this->attach($version, $files);
            $this->contentSecurity->linkToContent($security['record_ids'], $post, 1);
            if ($auto) {
                $this->grantPostReward($post);
            }

            return $post->fresh();
        });
    }

    public function createPostAsAdmin(AdminUser $admin, User $author, array $data): CommunityPost
    {
        $this->assertAllowed($author, 'post');
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        $hash = hash('sha256', 'admin:'.($key !== '' ? $key : Str::uuid()));
        if ($key !== '' && $existing = CommunityPost::withTrashed()->where('idempotency_key_hash', $hash)->first()) {
            return $existing;
        }
        $rules = $this->postRules();
        $this->validatePostText($data, $rules);
        $files = $this->validateAttachments($author, $data['image_file_ids'] ?? [], $data['video_file_id'] ?? null);
        // 后台代发仍走真实内容安全链路：命中拦截直接失败，疑似违规由运营本人确认后发布。
        $security = $this->scanPostForReview($author, $data);
        $note = trim((string) ($data['admin_note'] ?? '')) ?: '后台运营代发并直接发布。';

        return DB::transaction(function () use ($admin, $author, $data, $rules, $files, $security, $note, $hash) {
            $post = CommunityPost::query()->create([
                'user_id' => $author->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'category_code' => $data['category_code'] ?? 'experience',
                'location_name' => $this->normalizeLocation($data['location_name'] ?? null),
                'visibility' => $data['visibility'] ?? 'public',
                'location_latitude' => $data['location_latitude'] ?? null,
                'location_longitude' => $data['location_longitude'] ?? null,
                'status' => 'published',
                'published_version_no' => 1,
                'idempotency_key_hash' => $hash,
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note,
                'published_at' => now(),
            ]);
            $version = CommunityPostVersion::query()->create([
                'community_post_id' => $post->id,
                'version_no' => 1,
                'title' => $data['title'],
                'content' => $data['content'],
                'category_code' => $data['category_code'] ?? 'experience',
                'review_status' => 'approved',
                'rule_snapshot' => $rules,
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note,
                'reviewed_at' => now(),
            ]);
            $this->attach($version, $files);
            $this->contentSecurity->linkToContent($security['record_ids'], $post, 1);

            return $post->fresh();
        });
    }

    public function editPost(User $user, CommunityPost $post, array $data): CommunityPost
    {
        abort_unless($post->user_id === $user->id, 404);
        if (in_array($post->status, ['deleted', 'hidden'], true)) {
            throw ValidationException::withMessages(['post' => '当前帖子不可编辑。']);
        }
        $currentVersion = CommunityPostVersion::query()
            ->where('community_post_id', $post->id)
            ->where('version_no', $post->current_version_no)
            ->first();
        if ($currentVersion?->review_status === 'pending') {
            throw ValidationException::withMessages(['post' => '当前已有版本等待审核，请在审核完成后再编辑。']);
        }
        $rules = $this->postRules();
        $this->validatePostText($data, $rules);
        $files = $this->validateAttachments($user, $data['image_file_ids'] ?? [], $data['video_file_id'] ?? null);
        $security = $this->scanPostForReview($user, $data, $post);

        return DB::transaction(function () use ($post, $data, $rules, $files, $security) {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $versionNo = $locked->current_version_no + 1;
            $auto = $rules['review_mode'] === 'auto_publish' && ! $security['force_review'];
            $version = CommunityPostVersion::query()->create([
                'community_post_id' => $locked->id,
                'version_no' => $versionNo,
                'title' => $data['title'],
                'content' => $data['content'],
                'category_code' => $data['category_code'] ?? $locked->category_code,
                'review_status' => $auto ? 'approved' : 'pending',
                'rule_snapshot' => $rules,
                'reviewed_at' => $auto ? now() : null,
            ]);
            $this->attach($version, $files);
            $this->contentSecurity->linkToContent($security['record_ids'], $locked, $versionNo);
            $updates = [
                'current_version_no' => $versionNo,
                'location_name' => array_key_exists('location_name', $data)
                    ? $this->normalizeLocation($data['location_name'])
                    : $locked->location_name,
                'visibility' => $data['visibility'] ?? $locked->visibility,
                'location_latitude' => array_key_exists('location_latitude', $data) ? $data['location_latitude'] : $locked->location_latitude,
                'location_longitude' => array_key_exists('location_longitude', $data) ? $data['location_longitude'] : $locked->location_longitude,
            ];
            if ($auto) {
                $updates += ['title' => $data['title'], 'content' => $data['content'], 'category_code' => $data['category_code'] ?? $locked->category_code, 'status' => 'published', 'published_version_no' => $versionNo, 'published_at' => now()];
            } elseif ($locked->published_version_no === null) {
                $updates['status'] = 'pending_review';
            }
            $locked->update($updates);

            return $locked->fresh();
        });
    }

    public function createComment(User $user, CommunityPost $post, array $data, string $key, bool $followEnabled = true): CommunityComment
    {
        $this->assertAllowed($user, 'comment');
        abort_unless($post->status === 'published' && $post->isVisibleTo($user, $followEnabled), 404);
        $hash = hash('sha256', $key);
        if ($comment = CommunityComment::withTrashed()->where('idempotency_key_hash', $hash)->first()) {
            abort_unless($comment->user_id === $user->id, 404);

            return $comment;
        }
        $rules = $this->commentRules();
        $length = mb_strlen(trim($data['content']));
        if ($length < $rules['min_length'] || $length > $rules['max_length']) {
            throw ValidationException::withMessages(['content' => '评论长度不符合当前规则。']);
        }
        $this->enforceDailyLimit($user, CommunityComment::class, (int) $this->value('community.daily_comment_limit'));
        $parent = null;
        if (! empty($data['parent_comment_id'])) {
            $parent = CommunityComment::query()->where('public_id', $data['parent_comment_id'])->where('community_post_id', $post->id)->where('status', 'published')->first();
            if (! $parent || $parent->parent_comment_id !== null) {
                throw ValidationException::withMessages(['parent_comment_id' => '仅支持两级评论，父评论必须属于当前帖子。']);
            }
        }
        $sensitive = collect($this->value('community.sensitive_words'))->contains(fn ($word) => $word !== '' && str_contains($data['content'], (string) $word));
        $security = $this->scanFieldForReview($user, (string) $data['content'], 2, 'content', [
            'content_type' => 'comment',
            'campus_id' => $post->campus_id,
            'subject_snapshot' => $post->title,
        ]);
        $auto = $rules['review_mode'] === 'sensitive_filter' && ! $sensitive && ! $security['force_review'];

        return DB::transaction(function () use ($user, $post, $data, $hash, $rules, $parent, $auto, $security) {
            $comment = CommunityComment::query()->create([
                'community_post_id' => $post->id,
                'user_id' => $user->id,
                'parent_comment_id' => $parent?->id,
                'content' => $data['content'],
                'status' => $auto ? 'published' : 'pending_review',
                'rule_snapshot' => $rules,
                'idempotency_key_hash' => $hash,
                'published_at' => $auto ? now() : null,
            ]);
            $this->contentSecurity->linkToContent($security['record_ids'], $comment);
            if ($auto) {
                CommunityPost::query()->whereKey($post->id)->increment('comment_count');
                $this->grantCommentReward($comment);
                $this->notifyPublishedComment($comment);
            }

            return $comment->fresh();
        });
    }

    public function reviewPost(AdminUser $admin, CommunityPost $post, array $data): CommunityPost
    {
        return DB::transaction(function () use ($admin, $post, $data) {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $version = CommunityPostVersion::query()->where('community_post_id', $locked->id)->where('version_no', $locked->current_version_no)->where('review_status', 'pending')->lockForUpdate()->firstOrFail();
            if ($data['decision'] === 'approve') {
                if (in_array($locked->status, ['hidden', 'deleted'], true)) {
                    throw ValidationException::withMessages(['post' => '帖子已隐藏或删除，不能审核发布。']);
                }
                $version->update(['review_status' => 'approved', 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'reviewed_at' => now()]);
                $locked->update(['title' => $version->title, 'content' => $version->content, 'category_code' => $version->category_code, 'status' => 'published', 'published_version_no' => $version->version_no, 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'published_at' => now()]);
                $this->grantPostReward($locked);
            } else {
                $version->update(['review_status' => 'rejected', 'reviewed_by_admin_id' => $admin->id, 'review_reason_code' => $data['reason_code'], 'review_note' => $data['note'], 'reviewed_at' => now()]);
                if ($locked->published_version_no === null) {
                    $locked->update(['status' => 'rejected', 'reviewed_by_admin_id' => $admin->id, 'review_reason_code' => $data['reason_code'], 'review_note' => $data['note']]);
                }
            }

            return $locked->fresh();
        });
    }

    public function reviewComment(AdminUser $admin, CommunityComment $comment, array $data): CommunityComment
    {
        return DB::transaction(function () use ($admin, $comment, $data) {
            $locked = CommunityComment::query()->lockForUpdate()->findOrFail($comment->id);
            if ($locked->status !== 'pending_review') {
                throw ValidationException::withMessages(['comment' => '评论状态不可审核。']);
            }
            if ($data['decision'] === 'approve') {
                $locked->update(['status' => 'published', 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'reviewed_at' => now(), 'published_at' => now()]);
                CommunityPost::query()->whereKey($locked->community_post_id)->increment('comment_count');
                $this->grantCommentReward($locked);
                $this->notifyPublishedComment($locked);
                $postPublicId = CommunityPost::query()->whereKey($locked->community_post_id)->value('public_id');
                $this->notifications->create(User::query()->findOrFail($locked->user_id), 'community', 'comment_review_approved', '评论审核通过', '你的评论已通过审核并公开。', 'comment-review:'.$locked->public_id.':approved', false, ['in_app'], ['post_id' => $postPublicId, 'comment_id' => $locked->public_id]);
            } else {
                $locked->update(['status' => 'rejected', 'reviewed_by_admin_id' => $admin->id, 'review_reason_code' => $data['reason_code'], 'review_note' => $data['note'], 'reviewed_at' => now()]);
                $postPublicId = CommunityPost::query()->whereKey($locked->community_post_id)->value('public_id');
                $this->notifications->create(User::query()->findOrFail($locked->user_id), 'community', 'comment_review_rejected', '评论审核未通过', $data['note'], 'comment-review:'.$locked->public_id.':rejected', false, ['in_app'], ['post_id' => $postPublicId, 'comment_id' => $locked->public_id]);
            }

            return $locked->fresh();
        });
    }

    public function deletePost(User $user, CommunityPost $post): void
    {
        abort_unless($post->user_id === $user->id, 404);
        DB::transaction(function () use ($user, $post): void {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            if ($locked->reward_granted) {
                $points = (int) $this->value('points.source_reward_amounts')['post'];
                $this->assets->revokePointsWithDebt($user, $points, 'post', $locked->public_id, '帖子删除，撤销对应积分奖励。', 'community-post-delete:'.$locked->public_id);
            }
            $locked->update(['status' => 'deleted']);
            $locked->delete();
        });
    }

    private function grantPostReward(CommunityPost $post): void
    {
        if ($post->reward_granted) {
            return;
        }
        $points = (int) $this->value('points.source_reward_amounts')['post'];
        if ($points > 0) {
            $this->assets->grantPoints(User::query()->findOrFail($post->user_id), $points, 'post', $post->public_id, '帖子审核通过奖励。', 'community-post:'.$post->public_id);
        }
        $post->update(['reward_granted' => true]);
    }

    private function grantCommentReward(CommunityComment $comment): void
    {
        if ($comment->reward_granted || mb_strlen(trim($comment->content)) < (int) $this->value('community.comment_reward_min_length')) {
            return;
        }
        $rewardedToday = CommunityComment::query()->where('user_id', $comment->user_id)->where('reward_granted', true)->whereDate('published_at', today())->count();
        if ($rewardedToday >= (int) $this->value('community.daily_rewarded_comment_limit')) {
            return;
        }
        $points = (int) $this->value('points.source_reward_amounts')['comment'];
        if ($points > 0) {
            $this->assets->grantPoints(User::query()->findOrFail($comment->user_id), $points, 'comment', $comment->public_id, '有效评论奖励。', 'community-comment:'.$comment->public_id);
        }
        $comment->update(['reward_granted' => true]);
    }

    private function notifyPublishedComment(CommunityComment $comment): void
    {
        $post = CommunityPost::query()->findOrFail($comment->community_post_id);
        $actor = User::query()->findOrFail($comment->user_id);
        $parent = $comment->parent_comment_id
            ? CommunityComment::query()->find($comment->parent_comment_id)
            : null;
        $data = ['post_id' => $post->public_id, 'comment_id' => $comment->public_id];
        $variables = [
            'post_title' => $post->title,
            'status_text' => '已发布',
            'post_id' => $post->public_id,
            'review_note' => '',
            'comment_author' => $actor->nickname ?: '一位同学',
            'comment_excerpt' => Str::limit((string) preg_replace('/\s+/u', ' ', trim($comment->content)), 40),
            'occurred_at' => now()->format('Y-m-d H:i'),
        ];

        if ($parent !== null && $parent->user_id !== $actor->id) {
            $recipient = User::query()->findOrFail($parent->user_id);
            $this->notifyCommentInteraction(
                $recipient,
                'community_comment_replied',
                $variables,
                'community-comment:'.$comment->public_id.':reply:'.$recipient->public_id,
                $data + ['parent_comment_id' => $parent->public_id],
            );
        }

        if (($parent === null || $parent->user_id !== $post->user_id) && $post->user_id !== $actor->id) {
            $recipient = User::query()->findOrFail($post->user_id);
            $this->notifyCommentInteraction(
                $recipient,
                'community_post_commented',
                $variables,
                'community-comment:'.$comment->public_id.':post:'.$recipient->public_id,
                $data,
            );
        }
    }

    /**
     * 评论互动通知统一走业务通知事件，按后台配置同时投递站内信、小程序订阅消息和公众号通知。
     *
     * @param  array<string,string>  $variables
     * @param  array<string,mixed>  $data
     */
    private function notifyCommentInteraction(User $recipient, string $sceneCode, array $variables, string $eventKey, array $data): void
    {
        $event = $this->notificationEvents->record($recipient, $sceneCode, $variables, $eventKey, $data);
        // 与原有同步写站内信行为保持一致，立即处理；事务提交后的 afterCommit 回调会自动跳过已处理事件。
        $this->notificationEvents->process($event->id);
    }

    private function validateAttachments(User $user, array $imageIds, ?string $videoId): array
    {
        $imageIds = array_values(array_unique($imageIds));
        if (count($imageIds) > 9) {
            throw ValidationException::withMessages(['image_file_ids' => '帖子最多上传 9 张图片。']);
        }
        $ids = [...$imageIds, ...($videoId ? [$videoId] : [])];
        $files = UploadedFile::query()->whereIn('public_id', $ids)->get();
        if ($files->count() !== count($ids) || $files->contains(fn (UploadedFile $file) => $file->owner_user_id !== $user->id || $file->purpose !== 'community' || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['attachments' => '附件不存在、未通过安全扫描或不属于当前用户。']);
        }
        foreach ($imageIds as $id) {
            $file = $files->firstWhere('public_id', $id);
            if (! $file || ! str_starts_with((string) $file->detected_mime, 'image/')) {
                throw ValidationException::withMessages(['image_file_ids' => '图片附件类型无效。']);
            }
        }
        if ($videoId) {
            $video = $files->firstWhere('public_id', $videoId);
            if (! $video || ! str_starts_with((string) $video->detected_mime, 'video/') || (int) $video->actual_size > 100 * 1024 * 1024) {
                throw ValidationException::withMessages(['video_file_id' => '视频附件类型无效或超过 100MB。']);
            }
        }

        return ['images' => $imageIds, 'video' => $videoId, 'files' => $files];
    }

    private function attach(CommunityPostVersion $version, array $attachments): void
    {
        $sort = 0;
        foreach ($attachments['images'] as $publicId) {
            DB::table('community_attachments')->insert(['community_post_version_id' => $version->id, 'uploaded_file_id' => $attachments['files']->firstWhere('public_id', $publicId)->id, 'file_type' => 'image', 'sort_order' => $sort++, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($attachments['video']) {
            DB::table('community_attachments')->insert(['community_post_version_id' => $version->id, 'uploaded_file_id' => $attachments['files']->firstWhere('public_id', $attachments['video'])->id, 'file_type' => 'video', 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function validatePostText(array $data, array $rules): void
    {
        $title = mb_strlen(trim($data['title']));
        $content = mb_strlen(trim($data['content']));
        if ($title < $rules['title_min'] || $title > $rules['title_max']) {
            throw ValidationException::withMessages(['title' => '标题长度不符合当前规则。']);
        }
        if ($content < $rules['content_min'] || $content > $rules['content_max']) {
            throw ValidationException::withMessages(['content' => '正文长度不符合当前规则。']);
        }
    }

    private function enforceDailyLimit(User $user, string $model, int $limit): void
    {
        if ($model::withTrashed()->where('user_id', $user->id)->whereDate('created_at', today())->count() >= $limit) {
            throw ValidationException::withMessages(['limit' => '今日发布次数已达到后台配置上限。']);
        }
    }

    private function normalizeLocation(mixed $location): ?string
    {
        if (! is_string($location)) {
            return null;
        }
        $location = trim($location);

        return $location === '' ? null : mb_substr($location, 0, 80);
    }

    /**
     * 帖子标题与正文的内容安全检测（scene=3 论坛社区）。
     * 两个字段都必须检测，避免标题需复审时短路跳过正文。
     *
     * @param  array<string,mixed>  $data
     * @return array{force_review:bool,record_ids:array<int,int>}
     */
    private function scanPostForReview(User $user, array $data, ?CommunityPost $post = null): array
    {
        $context = [
            'content_type' => 'post',
            'campus_id' => $post?->campus_id ?? $user->campus_id,
            'content_id' => $post?->id,
            'content_public_id' => $post?->public_id,
            'content_version_no' => $post === null ? 1 : $post->current_version_no + 1,
            'subject_snapshot' => (string) ($data['title'] ?? ''),
        ];
        $title = $this->scanFieldForReview($user, (string) ($data['title'] ?? ''), 3, 'title', $context);
        $content = $this->scanFieldForReview($user, (string) ($data['content'] ?? ''), 3, 'content', $context);

        return [
            'force_review' => $title['force_review'] || $content['force_review'],
            'record_ids' => [...$title['record_ids'], ...$content['record_ids']],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{force_review:bool,record_ids:array<int,int>}
     */
    private function scanFieldForReview(User $user, string $text, int $scene, string $field, array $context = []): array
    {
        $scan = $this->contentSecurity->scanText($user, $text, $scene, $context + ['content_field' => $field]);
        if ($scan['verdict'] === CommunityContentSecurityService::BLOCK) {
            throw ValidationException::withMessages([
                $field => '内容包含违法违规或不当信息，无法发布，请修改后再试。',
            ]);
        }

        return [
            // fail-closed：除管理员显式关闭检测外，检测未执行（未配置/接口异常/无 openid 等）
            // 都不能当作通过，必须转人工审核。
            'force_review' => $scan['verdict'] === CommunityContentSecurityService::REVIEW
                || ($scan['verdict'] === CommunityContentSecurityService::SKIPPED
                    && ($scan['skip_reason'] ?? null) !== 'disabled'),
            'record_ids' => [$scan['record_id']],
        ];
    }

    private function postRules(): array
    {
        return ['review_mode' => $this->value('community.post_review_mode'), 'title_min' => (int) $this->value('community.post_title_min_length'), 'title_max' => (int) $this->value('community.post_title_max_length'), 'content_min' => (int) $this->value('community.post_content_min_length'), 'content_max' => (int) $this->value('community.post_content_max_length')];
    }

    private function commentRules(): array
    {
        return ['review_mode' => $this->value('community.comment_review_mode'), 'min_length' => (int) $this->value('community.comment_min_length'), 'max_length' => (int) $this->value('community.comment_max_length'), 'reward_min_length' => (int) $this->value('community.comment_reward_min_length')];
    }

    private function value(string $key): mixed
    {
        return $this->settings->read($key)['value'];
    }

    private function assertAllowed(User $user, string $operation): void
    {
        $types = $operation === 'post' ? ['post', 'mute', 'account'] : ['comment', 'mute', 'account'];
        $blocked = UserRestriction::query()->where('user_id', $user->id)->where('status', 'active')->whereIn('restriction_type', $types)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists();
        if ($blocked) {
            throw ValidationException::withMessages([$operation => '当前账号处于社区功能限制期。']);
        }
    }
}
