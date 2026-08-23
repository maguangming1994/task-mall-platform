<?php

namespace App\Modules\Task\Support;

final class TaskClaimState
{
    public const EXECUTION_RESERVED = 'reserved';

    public const EXECUTION_IN_PROGRESS = 'in_progress';

    public const EXECUTION_READY_TO_SUBMIT = 'ready_to_submit';

    public const EXECUTION_UNDER_REVIEW = 'under_review';

    public const EXECUTION_REVISION_REQUIRED = 'revision_required';

    public const EXECUTION_UNDER_APPEAL = 'under_appeal';

    public const EXECUTION_COMPLETED = 'completed';

    public const EXECUTION_ABANDONED = 'abandoned';

    public const EXECUTION_EXPIRED = 'expired';

    public const EXECUTION_TERMINATED = 'terminated';

    public const REVIEW_UNASSIGNED = 'unassigned';

    public const REVIEW_QUEUED = 'queued';

    public const REVIEW_REVIEWING = 'reviewing';

    public const REVIEW_NEEDS_REVISION = 'needs_revision';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_VOIDED = 'voided';

    /** @return array{execution_status:string,review_status:?string} */
    public static function fromLegacy(string $legacyStatus): array
    {
        return match ($legacyStatus) {
            'claimed' => ['execution_status' => self::EXECUTION_RESERVED, 'review_status' => null],
            'submitted' => ['execution_status' => self::EXECUTION_UNDER_REVIEW, 'review_status' => self::REVIEW_QUEUED],
            'reviewing' => ['execution_status' => self::EXECUTION_UNDER_REVIEW, 'review_status' => self::REVIEW_REVIEWING],
            'withdrawn' => ['execution_status' => self::EXECUTION_IN_PROGRESS, 'review_status' => self::REVIEW_VOIDED],
            'rejected' => ['execution_status' => self::EXECUTION_REVISION_REQUIRED, 'review_status' => self::REVIEW_NEEDS_REVISION],
            'appealed' => ['execution_status' => self::EXECUTION_UNDER_APPEAL, 'review_status' => self::REVIEW_REJECTED],
            'approved' => ['execution_status' => self::EXECUTION_COMPLETED, 'review_status' => self::REVIEW_APPROVED],
            'cancelled' => ['execution_status' => self::EXECUTION_ABANDONED, 'review_status' => self::REVIEW_VOIDED],
            'expired' => ['execution_status' => self::EXECUTION_EXPIRED, 'review_status' => self::REVIEW_VOIDED],
            'appeal_rejected' => ['execution_status' => self::EXECUTION_TERMINATED, 'review_status' => self::REVIEW_REJECTED],
            default => ['execution_status' => self::EXECUTION_RESERVED, 'review_status' => null],
        };
    }

    /** @return array<string,string> */
    public static function executionLabels(): array
    {
        return [
            self::EXECUTION_RESERVED => '待开始',
            self::EXECUTION_IN_PROGRESS => '进行中',
            self::EXECUTION_READY_TO_SUBMIT => '待提交',
            self::EXECUTION_UNDER_REVIEW => '审核中',
            self::EXECUTION_REVISION_REQUIRED => '待补交',
            self::EXECUTION_UNDER_APPEAL => '申诉中',
            self::EXECUTION_COMPLETED => '已完成',
            self::EXECUTION_ABANDONED => '已放弃',
            self::EXECUTION_EXPIRED => '已过期',
            self::EXECUTION_TERMINATED => '已关闭',
        ];
    }

    /** @return array<string,string> */
    public static function reviewLabels(): array
    {
        return [
            self::REVIEW_UNASSIGNED => '待分派',
            self::REVIEW_QUEUED => '待审核',
            self::REVIEW_REVIEWING => '审核中',
            self::REVIEW_NEEDS_REVISION => '需要补交',
            self::REVIEW_APPROVED => '审核通过',
            self::REVIEW_REJECTED => '最终驳回',
            self::REVIEW_VOIDED => '已撤销',
        ];
    }

    public static function executionLabel(string $status): string
    {
        return self::executionLabels()[$status] ?? $status;
    }

    public static function reviewLabel(?string $status): ?string
    {
        return $status === null ? null : (self::reviewLabels()[$status] ?? $status);
    }
}
