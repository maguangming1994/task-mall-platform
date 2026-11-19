<?php

namespace App\Modules\Push\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Push\Models\PushLevel;
use App\Modules\Push\Models\PushSettlement;
use App\Modules\Push\Models\PushSubmission;
use App\Modules\Push\Models\PushTask;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamApplication;
use App\Modules\Push\Models\PushTeamMember;
use App\Modules\Push\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPushController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    public function teams(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', 'in:recruiting,active,suspended,closed'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushTeam::query()->with(['leader', 'campus'])->latest('id');
        $query->when($filters['keyword'] ?? null, fn ($q, $keyword) => $q->where('name', 'like', "%{$keyword}%"));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTeam $team) => $this->push->presentTeam($team))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total' => PushTeam::query()->count(),
                'recruiting' => PushTeam::query()->where('status', 'recruiting')->count(),
                'members' => PushTeamMember::query()->where('status', 'active')->count(),
                'month_orders' => (int) PushSubmission::query()->where('status', 'approved')->where('reviewed_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function storeTeam(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => ['sometimes', 'integer', 'exists:campuses,id'],
            'name' => ['required', 'string', 'max:120'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:200'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'leader_user_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'level_code' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:recruiting,active,suspended,closed'],
            'settlement_cycle' => ['sometimes', 'in:weekly,monthly'],
            'review_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'member_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
        ]);
        $campusId = $this->resolveCampusId($request, $data['campus_id'] ?? null);
        /** @var AdminUser $admin */
        $admin = $request->user();

        $team = DB::transaction(function () use ($data, $campusId, $admin) {
            $leader = null;
            if (! empty($data['leader_user_id'])) {
                $leader = User::query()->where('public_id', $data['leader_user_id'])->first();
                if ($leader === null) {
                    throw ValidationException::withMessages(['leader_user_id' => ['团长用户不存在。']]);
                }
                $exists = PushTeamMember::query()->where('user_id', $leader->id)->where('status', 'active')->exists();
                if ($exists) {
                    throw ValidationException::withMessages(['leader_user_id' => ['该用户已加入其他团队。']]);
                }
            }
            $team = PushTeam::query()->create([
                'campus_id' => $campusId,
                'name' => $data['name'],
                'slogan' => $data['slogan'] ?? null,
                'intro' => $data['intro'] ?? null,
                'leader_user_id' => $leader?->id,
                'level_code' => $data['level_code'] ?? 'A1',
                'status' => $data['status'] ?? 'recruiting',
                'settlement_cycle' => $data['settlement_cycle'] ?? 'monthly',
                'review_hours' => $data['review_hours'] ?? 2,
                'member_limit' => $data['member_limit'] ?? 200,
                'member_count' => $leader === null ? 0 : 1,
                'stats_month' => now()->format('Y-m'),
                'created_by_admin_id' => $admin->id,
            ]);
            if ($leader !== null) {
                PushTeamMember::query()->create([
                    'campus_id' => $campusId,
                    'team_id' => $team->id,
                    'user_id' => $leader->id,
                    'role' => 'leader',
                    'level_code' => $data['level_code'] ?? 'A1',
                    'status' => 'active',
                    'stats_month' => now()->format('Y-m'),
                    'joined_at' => now(),
                ]);
            }
            $this->push->syncMemberCount($team);

            return $team;
        });

        $presented = $this->push->presentTeam($team);
        $audit->record($admin, 'push_team.create', 'push_team', $team->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function updateTeam(Request $request, PushTeam $team, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:200'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'level_code' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:recruiting,active,suspended,closed'],
            'settlement_cycle' => ['sometimes', 'in:weekly,monthly'],
            'review_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'member_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'leader_user_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentTeam($team);

        DB::transaction(function () use ($team, $data) {
            if (array_key_exists('leader_user_id', $data)) {
                $leader = null;
                if (! empty($data['leader_user_id'])) {
                    $leader = User::query()->where('public_id', $data['leader_user_id'])->first();
                    if ($leader === null) {
                        throw ValidationException::withMessages(['leader_user_id' => ['团长用户不存在。']]);
                    }
                    $occupied = PushTeamMember::query()
                        ->where('user_id', $leader->id)
                        ->where('status', 'active')
                        ->where('team_id', '!=', $team->id)
                        ->exists();
                    if ($occupied) {
                        throw ValidationException::withMessages(['leader_user_id' => ['该用户已在其他团队任职，不能同时担任团长。']]);
                    }
                }
                // 换人或置空时，原团长记录统一下降为普通成员，避免出现多个团长
                PushTeamMember::query()->where('team_id', $team->id)->where('role', 'leader')->update(['role' => 'member']);
                $team->leader_user_id = $leader?->id;
                if ($leader !== null) {
                    PushTeamMember::query()->updateOrCreate(
                        ['team_id' => $team->id, 'user_id' => $leader->id],
                        [
                            'campus_id' => $team->campus_id,
                            'role' => 'leader',
                            'level_code' => $team->level_code,
                            'status' => 'active',
                            'stats_month' => now()->format('Y-m'),
                            'joined_at' => now(),
                            'left_at' => null,
                        ],
                    );
                }
            }
            $team->fill(collect($data)->except('leader_user_id')->all())->save();
            $this->push->syncMemberCount($team);
        });

        $after = $this->push->presentTeam($team->fresh());
        $audit->record($admin, 'push_team.update', 'push_team', $team->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function members(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'team_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:60'],
            'level_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'in:active,left'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushTeamMember::query()->with(['user', 'team'])->latest('id');
        if (! empty($filters['team_id'])) {
            $teamId = PushTeam::query()->where('public_id', $filters['team_id'])->value('id');
            $query->where('team_id', $teamId ?? 0);
        }
        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->whereHas('user', fn ($q) => $q->where('nickname', 'like', "%{$keyword}%"));
        }
        $query->when($filters['level_code'] ?? null, fn ($q, $level) => $q->where('level_code', $level));
        $query->where('status', $filters['status'] ?? 'active');
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTeamMember $member) => [
                'id' => $member->id,
                'user_id' => $member->user?->public_id,
                'nickname' => $member->user?->nickname ?: '成员',
                'team_name' => $member->team?->name,
                'role' => $member->role,
                'level_code' => $member->level_code,
                'status' => $member->status,
                'month_orders' => (int) $member->month_orders,
                'month_commission' => $this->push->money((int) $member->month_commission_cents),
                'joined_at' => $member->joined_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'active' => PushTeamMember::query()->where('status', 'active')->count(),
                'leaders' => PushTeamMember::query()->where('status', 'active')->where('role', 'leader')->count(),
                'month_orders' => (int) PushTeamMember::query()->where('status', 'active')->sum('month_orders'),
            ],
        ]);
    }

    public function updateMember(Request $request, PushTeamMember $member, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'level_code' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:active,left'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = ['level_code' => $member->level_code, 'status' => $member->status];
        DB::transaction(function () use ($member, $data) {
            if (($data['status'] ?? null) === 'left' && $member->status === 'active') {
                if ($member->role === 'leader') {
                    throw ValidationException::withMessages(['status' => ['该成员为团队团长，请先更换团长后再移出团队。']]);
                }
                $member->left_at = now();
            }
            if (($data['status'] ?? null) === 'active' && $member->status === 'left') {
                $member->left_at = null;
            }
            $member->fill($data)->save();
            $team = PushTeam::query()->find($member->team_id);
            if ($team !== null) {
                $this->push->syncMemberCount($team);
            }
        });
        $audit->record($admin, 'push_member.update', 'push_team_member', (string) $member->id, $before, $data);

        return ApiResponse::success(['updated' => true]);
    }

    public function levels(): JsonResponse
    {
        $items = PushLevel::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PushLevel $level) => $this->push->presentLevel($level))->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function storeLevel(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:48'],
            'coefficient' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'team_bonus_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_monthly_orders' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $level = PushLevel::query()->updateOrCreate(['code' => $data['code']], $data);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $audit->record($admin, 'push_level.save', 'push_level', $level->code, null, $data);

        return ApiResponse::success($this->push->presentLevel($level), 201);
    }

    public function updateLevel(Request $request, PushLevel $level, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:48'],
            'coefficient' => ['sometimes', 'numeric', 'min:0.1', 'max:5'],
            'team_bonus_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_monthly_orders' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $before = $this->push->presentLevel($level);
        $level->fill($data)->save();
        /** @var AdminUser $admin */
        $admin = $request->user();
        $audit->record($admin, 'push_level.update', 'push_level', $level->code, $before, $data);

        return ApiResponse::success($this->push->presentLevel($level->fresh()));
    }

    public function tasks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:draft,published,closed'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushTask::query()->latest('id');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['keyword'] ?? null, fn ($q, $keyword) => $q->where('title', 'like', "%{$keyword}%"));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTask $task) => $this->push->presentTask($task))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function storeTask(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => ['sometimes', 'integer', 'exists:campuses,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cover_text' => ['sometimes', 'nullable', 'string', 'max:24'],
            'reward_amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'quota' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'in:draft,published,closed'],
        ]);
        $campusId = $this->resolveCampusId($request, $data['campus_id'] ?? null);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $task = PushTask::query()->create([
            'campus_id' => $campusId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_text' => $data['cover_text'] ?? null,
            'reward_cents' => (int) round($data['reward_amount'] * 100),
            'quota' => $data['quota'] ?? 0,
            'per_user_limit' => $data['per_user_limit'] ?? 1,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by_admin_id' => $admin->id,
        ]);
        $presented = $this->push->presentTask($task);
        $audit->record($admin, 'push_task.create', 'push_task', $task->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function updateTask(Request $request, PushTask $task, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cover_text' => ['sometimes', 'nullable', 'string', 'max:24'],
            'reward_amount' => ['sometimes', 'numeric', 'min:0.01', 'max:100000'],
            'quota' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:draft,published,closed'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentTask($task);
        $startsAt = array_key_exists('starts_at', $data) ? $data['starts_at'] : $task->starts_at;
        $endsAt = array_key_exists('ends_at', $data) ? $data['ends_at'] : $task->ends_at;
        if ($startsAt !== null && $endsAt !== null && Carbon::parse($endsAt)->lt(Carbon::parse($startsAt))) {
            throw ValidationException::withMessages(['ends_at' => ['结束时间不能早于开始时间。']]);
        }
        if (array_key_exists('reward_amount', $data)) {
            $data['reward_cents'] = (int) round($data['reward_amount'] * 100);
            unset($data['reward_amount']);
        }
        $task->fill($data)->save();
        $after = $this->push->presentTask($task->fresh());
        $audit->record($admin, 'push_task.update', 'push_task', $task->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function applications(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'type' => ['sometimes', 'in:join,leader'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushTeamApplication::query()->with(['user', 'team'])->latest('id');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTeamApplication $application) => $this->push->presentApplication($application))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'pending' => PushTeamApplication::query()->where('status', 'pending')->count(),
                'leader_pending' => PushTeamApplication::query()->where('type', 'leader')->where('status', 'pending')->count(),
            ],
        ]);
    }

    public function reviewApplication(Request $request, PushTeamApplication $application, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentApplication($application);
        $reviewed = $this->push->reviewApplication(null, $admin, $application, $data['decision'] === 'approve', $data['note'] ?? null);
        $audit->record($admin, 'push_application.review', 'push_team_application', $application->public_id, $before, $this->push->presentApplication($reviewed));

        return ApiResponse::success($this->push->presentApplication($reviewed));
    }

    public function submissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushSubmission::query()->with(['task', 'user'])->latest('id');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['keyword'] ?? null, fn ($q, $keyword) => $q->whereHas('task', fn ($t) => $t->where('title', 'like', "%{$keyword}%")));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushSubmission $submission) => $this->push->presentSubmission($submission))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'pending' => PushSubmission::query()->where('status', 'pending')->count(),
                'approved' => PushSubmission::query()->where('status', 'approved')->count(),
                'rejected' => PushSubmission::query()->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function reviewSubmission(Request $request, PushSubmission $submission, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        if ($data['decision'] === 'reject' && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => ['驳回时必须填写原因。']]);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentSubmission($submission);
        $reviewed = $this->push->reviewSubmission($admin, $submission, $data['decision'] === 'approve', $data['reason'] ?? null);
        $audit->record($admin, 'push_submission.review', 'push_submission', $submission->public_id, $before, $this->push->presentSubmission($reviewed));

        return ApiResponse::success($this->push->presentSubmission($reviewed));
    }

    public function settlements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:draft,pending,paid'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PushSettlement::query()->latest('id');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushSettlement $settlement) => $this->push->presentSettlement($settlement))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'pending_amount' => $this->push->money((int) PushSettlement::query()->whereIn('status', ['draft', 'pending'])->sum('total_commission_cents')),
                'paid_amount' => $this->push->money((int) PushSettlement::query()->where('status', 'paid')->sum('total_commission_cents')),
            ],
        ]);
    }

    public function storeSettlement(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'campus_id' => ['sometimes', 'integer', 'exists:campuses,id'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $campusId = $this->resolveCampusId($request, $data['campus_id'] ?? null, false);
        $settlement = $this->push->buildSettlement($admin, $data['period_start'], $data['period_end'], $campusId);
        $audit->record($admin, 'push_settlement.create', 'push_settlement', $settlement->public_id, null, $this->push->presentSettlement($settlement));

        return ApiResponse::success($this->push->presentSettlement($settlement, true), 201);
    }

    public function settlement(Request $request, PushSettlement $settlement): JsonResponse
    {
        return ApiResponse::success($this->push->presentSettlement($settlement, true));
    }

    public function confirmSettlement(Request $request, PushSettlement $settlement, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentSettlement($settlement);
        $confirmed = $this->push->confirmSettlement($settlement);
        $audit->record($admin, 'push_settlement.confirm', 'push_settlement', $settlement->public_id, $before, $this->push->presentSettlement($confirmed));

        return ApiResponse::success($this->push->presentSettlement($confirmed, true));
    }

    public function paySettlement(Request $request, PushSettlement $settlement, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $this->push->presentSettlement($settlement);
        $paid = $this->push->paySettlement($admin, $settlement);
        $audit->record($admin, 'push_settlement.pay', 'push_settlement', $settlement->public_id, $before, $this->push->presentSettlement($paid));

        return ApiResponse::success($this->push->presentSettlement($paid, true));
    }

    private function resolveCampusId(Request $request, ?int $input, bool $required = true): ?int
    {
        $campusId = $input ?? $request->attributes->get('campus_database_id');
        if ($required && $campusId === null) {
            throw ValidationException::withMessages(['campus_id' => ['请先选择校区。']]);
        }

        return $campusId;
    }
}
