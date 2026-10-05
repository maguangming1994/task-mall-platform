<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 管理员人工实名认证审核（后台与移动工作台共用）。
 *
 * 仅返回脱敏姓名/证件号，原始证件号不可逆，不提供明文查看。
 */
class AdminIdentityVerificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,verified,failed,provider_error'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);

        $query = UserIdentityVerification::query()
            ->with(['user', 'reviewer'])
            // 校园代理处于严格校园上下文时，User 全局作用域会自动限定本校，
            // whereHas 保证列表只出现本校学生的实名记录，避免跨校 PII 泄露。
            ->whereHas('user')
            ->when($data['status'] ?? 'pending', fn ($q, $status) => $q->where('status', $status))
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (UserIdentityVerification $v) => $this->present($v)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function review(Request $request, UserIdentityVerification $verification, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        // 校园数据范围：校园代理只能审核本校学生（关联 user 受全局作用域影响，
        // 这里绕过全局作用域取真实 campus_id 判定）
        if ($this->campusContextId($request) !== null) {
            $ownerCampusId = User::withoutGlobalScopes()->where('id', $verification->user_id)->value('campus_id');
            if ((int) $ownerCampusId !== $this->campusContextId($request)) {
                return ApiResponse::error('CAMPUS_SCOPE_DENIED', '无权审核其他学校用户的实名申请。', 403);
            }
        }

        $verification->loadMissing(['user', 'reviewer']);
        $before = $this->present($verification);

        // 事务内行锁 + 终态判定，避免并发重复审核（TOCTOU）导致双写/双通知
        $updated = DB::transaction(function () use ($verification, $data, $admin): ?UserIdentityVerification {
            $locked = UserIdentityVerification::query()->lockForUpdate()->find($verification->id);
            if ($locked->status !== 'pending') {
                return null;
            }

            if ($data['decision'] === 'approve') {
                $locked->update([
                    'status' => 'verified',
                    'failure_code' => null,
                    'verified_at' => now(),
                    'reviewed_by_admin_id' => $admin->id,
                    'reviewed_at' => now(),
                    'review_note' => $data['note'] ?? null,
                ]);
            } else {
                $locked->update([
                    'status' => 'failed',
                    'failure_code' => 'manual_rejected',
                    'reviewed_by_admin_id' => $admin->id,
                    'reviewed_at' => now(),
                    'review_note' => $data['note'] ?? null,
                ]);
            }

            return $locked->refresh();
        });

        if ($updated === null) {
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该实名认证申请已经完成审核，不能重复操作。',
                409,
            );
        }

        $verification->setRawAttributes($updated->getAttributes(), true);
        $verification->load(['user', 'reviewer']);
        $after = $this->present($verification);

        $audit->record(
            $admin,
            'identity.manual_review',
            'user_identity_verification',
            $verification->public_id,
            $before,
            $after,
            '审核'.($data['decision'] === 'approve' ? '通过' : '驳回').(isset($data['note']) ? '：'.$data['note'] : ''),
        );
        $this->notifyUser($verification, $data['decision'] === 'approve', $data['note'] ?? null);

        return ApiResponse::success($after);
    }

    /** 严格校园上下文中的校园 ID；超管/平台管理员返回 null（不限校） */
    private function campusContextId(Request $request): ?int
    {
        $campusId = $request->attributes->get('campus_database_id');

        return $request->attributes->get('campus_context_strict') === true && is_int($campusId) ? $campusId : null;
    }

    private function present(UserIdentityVerification $verification): array
    {
        $user = $verification->relationLoaded('user') ? $verification->user : null;

        return [
            'id' => $verification->public_id,
            'status' => $verification->status,
            'provider' => $verification->provider,
            'real_name_masked' => $verification->real_name_masked,
            'identity_number_masked' => $verification->identity_number_masked,
            'failure_code' => $verification->failure_code,
            'submitted_at' => $verification->created_at?->toIso8601String(),
            'verified_at' => $verification->verified_at?->toIso8601String(),
            'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
            'review_note' => $verification->review_note,
            'reviewed_by_name' => $verification->relationLoaded('reviewer')
                ? $verification->reviewer?->display_name
                : null,
            'user' => $user ? [
                'id' => $user->public_id,
                'nickname' => $user->nickname,
                'avatar' => $user->avatar_file_id,
            ] : null,
        ];
    }

    private function notifyUser(UserIdentityVerification $verification, bool $approved, ?string $note): void
    {
        $user = $verification->user;
        if ($user === null) {
            return;
        }

        if ($approved) {
            $this->notifications->create(
                $user, 'identity', 'identity_verified',
                '实名认证已通过',
                '恭喜，你的实名认证已人工审核通过。',
                'identity-review:'.$verification->public_id.':approved',
                false, ['in_app'],
                ['verification_id' => $verification->public_id],
            );
        } else {
            $this->notifications->create(
                $user, 'identity', 'identity_rejected',
                '实名认证未通过',
                '你的实名认证未通过审核'.($note ? '：'.$note : '，请核对信息后重新提交。'),
                'identity-review:'.$verification->public_id.':rejected',
                false, ['in_app'],
                ['verification_id' => $verification->public_id],
            );
        }
    }
}
