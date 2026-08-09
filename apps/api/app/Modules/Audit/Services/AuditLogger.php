<?php

namespace App\Modules\Audit\Services;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Models\OperationLog;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        ?AdminUser $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): OperationLog {
        $ip = request()->ip();

        return OperationLog::query()->create([
            'admin_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_value' => $before,
            'after_value' => $after,
            'reason' => $reason,
            'ip_hash' => $ip === null ? null : hash_hmac(
                'sha256',
                $ip,
                hash('sha256', (string) config('auth_tokens.identity_hash_secret')),
            ),
            'request_id' => ApiResponse::requestId(),
        ]);
    }
}
