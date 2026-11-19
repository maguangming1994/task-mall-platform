<?php

namespace App\Modules\Push\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Push\Models\PushPosition;
use App\Modules\Push\Models\PushSubmission;
use App\Modules\Push\Models\PushTask;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamApplication;
use App\Modules\Push\Models\PushTeamMember;
use App\Modules\Push\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPushController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    public function center(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->push->activeMembership($user);
        $team = $membership?->team;
        $level = $this->push->level($membership?->level_code ?? 'A1');
        $monthStart = now()->startOfMonth();

        $monthQuery = PushSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('reviewed_at', '>=', $monthStart);
        $monthOrders = (clone $monthQuery)->count();
        $monthCommission = (int) (clone $monthQuery)->sum('commission_cents');

        $approvedQuery = PushSubmission::query()->where('user_id', $user->id)->where('status', 'approved');
        $pendingAmount = (int) (clone $approvedQuery)->whereNull('settled_at')->sum('commission_cents');
        $settledAmount = (int) (clone $approvedQuery)->whereNotNull('settled_at')->sum('commission_cents');

        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);
        $pendingApplications = $team !== null && (int) $team->leader_user_id === (int) $user->id
            ? PushTeamApplication::query()->where('team_id', $team->id)->where('status', 'pending')->count()
            : 0;

        return ApiResponse::success([
            'wallet' => [
                'available_amount' => $this->push->money((int) $account->wallet_available_cents),
                'pending_amount' => $this->push->money($pendingAmount),
                'settled_amount' => $this->push->money($settledAmount),
                'month_commission' => $this->push->money($monthCommission),
                'month_orders' => $monthOrders,
                'avg_commission' => $monthOrders > 0 ? $this->push->money((int) round($monthCommission / $monthOrders)) : '0.00',
                'level_code' => $level->code,
                'level_name' => $level->name,
            ],
            'membership' => $this->presentMembership($membership),
            'team' => $team === null ? null : $this->push->presentTeam($team),
            'is_leader' => $team !== null && (int) $team->leader_user_id === (int) $user->id,
            'tasks' => PushTask::query()
                ->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->latest('id')
                ->limit(3)
                ->get()
                ->map(fn (PushTask $task) => $this->push->presentTask($task))->values(),
            'counts' => [
                'positions' => PushPosition::query()->where('user_id', $user->id)->where('status', 'active')->count(),
                'pending_submissions' => PushSubmission::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
                'pending_applications' => $pendingApplications,
            ],
            'levels' => collect($this->push->levels())->map(fn ($item) => $this->push->presentLevel($item))->values(),
        ]);
    }

    public function teams(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort' => ['sometimes', 'in:default,commission,members'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = PushTeam::query()->with(['leader', 'campus'])->whereIn('status', ['recruiting', 'active']);
        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('slogan', 'like', "%{$keyword}%"));
        }
        match ($filters['sort'] ?? 'default') {
            'commission' => $query->orderByDesc('month_commission_cents'),
            'members' => $query->orderByDesc('member_count'),
            default => $query->orderByDesc('member_count')->orderByDesc('id'),
        };
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTeam $team) => $this->push->presentTeam($team))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function team(Request $request, PushTeam $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->push->activeMembership($user);

        return ApiResponse::success([
            'team' => $this->push->presentTeam($team, true),
            'conditions' => $this->push->entryConditions($user, $team),
            'is_member' => $membership !== null && (int) $membership->team_id === (int) $team->id,
            'pending_application' => PushTeamApplication::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists(),
        ]);
    }

    public function apply(Request $request, PushTeam $team): JsonResponse
    {
        $data = $request->validate(['message' => ['sometimes', 'nullable', 'string', 'max:300']]);
        /** @var User $user */
        $user = $request->user();
        $application = $this->push->apply($user, $team, $data['message'] ?? null);

        return ApiResponse::success($this->push->presentApplication($application), 201);
    }

    public function applyAsLeader(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'message' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $application = $this->push->applyAsLeader($user, $data['team_name'] ?? null, $data['message'] ?? null);

        return ApiResponse::success($this->push->presentApplication($application), 201);
    }

    public function myTeam(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->push->activeMembership($user);
        if ($membership === null || $membership->team === null) {
            return ApiResponse::success([
                'membership' => null,
                'team' => null,
                'members' => [],
                'applications' => [],
                'is_leader' => false,
            ]);
        }
        $team = $membership->team;
        $isLeader = (int) $team->leader_user_id === (int) $user->id;
        $members = PushTeamMember::query()
            ->with('user')
            ->where('team_id', $team->id)
            ->where('status', 'active')
            ->orderByRaw("CASE role WHEN 'leader' THEN 0 ELSE 1 END")
            ->orderByDesc('month_orders')
            ->get()
            ->map(fn (PushTeamMember $member) => [
                'user_id' => $member->user?->public_id,
                'nickname' => $member->user?->nickname ?: '成员',
                'role' => $member->role,
                'level_code' => $member->level_code,
                'month_orders' => (int) $member->month_orders,
                'month_commission' => $this->push->money((int) $member->month_commission_cents),
                'joined_at' => $member->joined_at?->toIso8601String(),
            ])->values();

        return ApiResponse::success([
            'membership' => $this->presentMembership($membership),
            'team' => $this->push->presentTeam($team),
            'members' => $members,
            'applications' => $isLeader
                ? PushTeamApplication::query()
                    ->with('user')
                    ->where('team_id', $team->id)
                    ->where('status', 'pending')
                    ->latest('id')
                    ->get()
                    ->map(fn (PushTeamApplication $application) => $this->push->presentApplication($application))->values()
                : [],
            'is_leader' => $isLeader,
        ]);
    }

    public function reviewApplication(Request $request, PushTeamApplication $application): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $reviewed = $this->push->reviewApplication($user, null, $application, $data['decision'] === 'approve', $data['note'] ?? null);

        return ApiResponse::success($this->push->presentApplication($reviewed));
    }

    public function leave(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->push->leaveTeam($user);

        return ApiResponse::success(['left' => true]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $filters = $request->validate(['page_size' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $paginator = PushTask::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest('id')
            ->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (PushTask $task) => $this->push->presentTask($task))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function task(Request $request, PushTask $task): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->push->activeMembership($user);

        return ApiResponse::success([
            'task' => $this->push->presentTask($task),
            'is_member' => $membership !== null,
            'my_positions' => PushPosition::query()
                ->where('task_id', $task->id)
                ->where('user_id', $user->id)
                ->latest('id')
                ->get()
                ->map(fn (PushPosition $position) => $this->push->presentPosition($position))->values(),
        ]);
    }

    public function claim(Request $request, PushTask $task): JsonResponse
    {
        $data = $request->validate(['keyword' => ['sometimes', 'nullable', 'string', 'max:120']]);
        /** @var User $user */
        $user = $request->user();
        $position = $this->push->claimPosition($user, $task, $data['keyword'] ?? null);

        return ApiResponse::success($this->push->presentPosition($position), 201);
    }

    public function positions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->push->expirePositionsFor($user);
        $items = PushPosition::query()
            ->with('task')
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PushPosition $position) => $this->push->presentPosition($position))->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function submit(Request $request, PushPosition $position): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:9'],
            'images.*' => ['string', 'max:500'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $submission = $this->push->submit($user, $position, $data);

        return ApiResponse::success($this->push->presentSubmission($submission), 201);
    }

    public function submissions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected']])['status'] ?? null;
        $items = PushSubmission::query()
            ->with(['task', 'position'])
            ->where('user_id', $user->id)
            ->when($status, fn ($q, $value) => $q->where('status', $value))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PushSubmission $submission) => $this->push->presentSubmission($submission))->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function earnings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);
        $approved = PushSubmission::query()->where('user_id', $user->id)->where('status', 'approved');
        $pending = (int) (clone $approved)->whereNull('settled_at')->sum('commission_cents');
        $settled = (int) (clone $approved)->whereNotNull('settled_at')->sum('commission_cents');
        $membership = $this->push->activeMembership($user);

        return ApiResponse::success([
            'wallet' => [
                'available_amount' => $this->push->money((int) $account->wallet_available_cents),
                'pending_amount' => $this->push->money($pending),
                'settled_amount' => $this->push->money($settled),
            ],
            'level' => $this->push->presentLevel($this->push->level($membership?->level_code ?? 'A1')),
            'items' => PushSubmission::query()
                ->with(['task'])
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'pending'])
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (PushSubmission $submission) => [
                    'id' => $submission->public_id,
                    'task_title' => $submission->task?->title,
                    'status' => $submission->status,
                    'commission' => $this->push->money((int) $submission->commission_cents),
                    'settled' => $submission->settled_at !== null,
                    'created_at' => $submission->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    private function presentMembership(?PushTeamMember $membership): ?array
    {
        if ($membership === null) {
            return null;
        }

        return [
            'team_id' => $membership->team?->public_id,
            'team_name' => $membership->team?->name,
            'role' => $membership->role,
            'level_code' => $membership->level_code,
            'month_orders' => (int) $membership->month_orders,
            'month_commission' => $this->push->money((int) $membership->month_commission_cents),
            'joined_at' => $membership->joined_at?->toIso8601String(),
        ];
    }
}
