<?php

namespace App\Modules\Push\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Push\Models\PushLevel;
use App\Modules\Push\Models\PushPosition;
use App\Modules\Push\Models\PushSettlement;
use App\Modules\Push\Models\PushSettlementItem;
use App\Modules\Push\Models\PushSubmission;
use App\Modules\Push\Models\PushTask;
use App\Modules\Push\Models\PushTeam;
use App\Modules\Push\Models\PushTeamApplication;
use App\Modules\Push\Models\PushTeamMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PushService
{
    /**
     * 平台等级规则（无数据时提供确定性兜底，保证佣金计算不中断）。
     *
     * @return array<string, PushLevel>
     */
    public function levels(): array
    {
        $levels = PushLevel::query()->orderBy('sort_order')->orderBy('id')->get();
        $map = [];
        foreach ($levels as $level) {
            $map[$level->code] = $level;
        }
        if ($map === []) {
            foreach ([
                ['code' => 'A1', 'name' => 'A1 新手', 'coefficient' => '1.000', 'team_bonus_rate' => '0.000', 'min_monthly_orders' => 0],
                ['code' => 'A2', 'name' => 'A2 熟练', 'coefficient' => '1.100', 'team_bonus_rate' => '0.030', 'min_monthly_orders' => 30],
                ['code' => 'A3', 'name' => 'A3 核心', 'coefficient' => '1.200', 'team_bonus_rate' => '0.050', 'min_monthly_orders' => 80],
            ] as $index => $attributes) {
                $map[$attributes['code']] = new PushLevel($attributes + ['sort_order' => $index, 'status' => 'active']);
            }
        }

        return $map;
    }

    public function level(string $code): PushLevel
    {
        $levels = $this->levels();

        return $levels[$code] ?? $levels['A1'] ?? new PushLevel(['code' => 'A1', 'name' => 'A1 新手', 'coefficient' => '1.000', 'team_bonus_rate' => '0.000']);
    }

    public function presentLevel(PushLevel $level): array
    {
        return [
            'code' => $level->code,
            'name' => $level->name,
            'coefficient' => (float) $level->coefficient,
            'team_bonus_rate' => (float) $level->team_bonus_rate,
            'min_monthly_orders' => (int) $level->min_monthly_orders,
            'sort_order' => (int) $level->sort_order,
            'status' => $level->status,
        ];
    }

    public function presentTeam(PushTeam $team, bool $detail = false): array
    {
        $leader = $team->relationLoaded('leader') ? $team->leader : $team->leader()->first();
        $stats = $this->teamStats($team);
        $data = [
            'id' => $team->public_id,
            'name' => $team->name,
            'slogan' => $team->slogan,
            'intro' => $team->intro,
            'level_code' => $team->level_code,
            'level_name' => $this->level($team->level_code)->name,
            'status' => $team->status,
            'settlement_cycle' => $team->settlement_cycle,
            'review_hours' => (int) $team->review_hours,
            'member_limit' => (int) $team->member_limit,
            'member_count' => (int) $team->member_count,
            'month_orders' => $stats['month_orders'],
            'month_commission' => $this->money($stats['month_commission_cents']),
            'team_bonus_rate' => (float) $this->level($team->level_code)->team_bonus_rate,
            'leader' => $leader === null ? null : [
                'id' => $leader->public_id,
                'nickname' => $leader->nickname ?: '团长',
            ],
            'campus' => $team->relationLoaded('campus') && $team->campus !== null
                ? ['id' => $team->campus->public_id, 'name' => $team->campus->name]
                : null,
        ];

        if ($detail) {
            $data['ranking'] = PushTeamMember::query()
                ->with('user')
                ->where('team_id', $team->id)
                ->where('status', 'active')
                ->orderByDesc('month_orders')
                ->limit(10)
                ->get()
                ->map(fn (PushTeamMember $member) => [
                    'user_id' => $member->user?->public_id,
                    'nickname' => $member->user?->nickname ?: '成员',
                    'level_code' => $member->level_code,
                    'month_orders' => (int) $member->month_orders,
                    'month_commission' => $this->money((int) $member->month_commission_cents),
                ])->values();
        }

        return $data;
    }

    public function presentTask(PushTask $task): array
    {
        $remaining = $task->quota > 0 ? max(0, (int) $task->quota - (int) $task->claimed_count) : null;

        return [
            'id' => $task->public_id,
            'title' => $task->title,
            'description' => $task->description,
            'cover_text' => $task->cover_text,
            'reward_amount' => $this->money((int) $task->reward_cents),
            'quota' => (int) $task->quota,
            'claimed_count' => (int) $task->claimed_count,
            'remaining' => $remaining,
            'per_user_limit' => (int) $task->per_user_limit,
            'starts_at' => $task->starts_at?->toIso8601String(),
            'ends_at' => $task->ends_at?->toIso8601String(),
            'status' => $task->status,
        ];
    }

    public function presentPosition(PushPosition $position): array
    {
        $position->loadMissing('task');

        return [
            'id' => $position->public_id,
            'keyword' => $position->keyword,
            'status' => $position->status,
            'claimed_at' => $position->claimed_at?->toIso8601String(),
            'expires_at' => $position->expires_at?->toIso8601String(),
            'task' => $position->task === null ? null : $this->presentTask($position->task),
        ];
    }

    public function presentSubmission(PushSubmission $submission): array
    {
        $submission->loadMissing(['task', 'position']);

        return [
            'id' => $submission->public_id,
            'status' => $submission->status,
            'images' => $submission->images ?: [],
            'remark' => $submission->remark,
            'contact_name' => $submission->contact_name,
            'contact_phone' => $submission->contact_phone,
            'reject_reason' => $submission->reject_reason,
            'level_code' => $submission->level_code,
            'commission' => $this->money((int) $submission->commission_cents),
            'team_bonus' => $this->money((int) $submission->team_bonus_cents),
            'settled' => $submission->settled_at !== null,
            'created_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'task' => $submission->task === null ? null : $this->presentTask($submission->task),
            'user' => $submission->relationLoaded('user') && $submission->user !== null ? [
                'id' => $submission->user->public_id,
                'nickname' => $submission->user->nickname ?: '用户',
            ] : null,
        ];
    }

    public function presentApplication(PushTeamApplication $application): array
    {
        $application->loadMissing(['user', 'team']);

        return [
            'id' => $application->public_id,
            'type' => $application->type,
            'status' => $application->status,
            'team_name' => $application->team_name,
            'message' => $application->message,
            'review_note' => $application->review_note,
            'created_at' => $application->created_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'user' => $application->user === null ? null : [
                'id' => $application->user->public_id,
                'nickname' => $application->user->nickname ?: '用户',
            ],
            'team' => $application->team === null ? null : [
                'id' => $application->team->public_id,
                'name' => $application->team->name,
            ],
        ];
    }

    public function presentSettlement(PushSettlement $settlement, bool $withItems = false): array
    {
        $data = [
            'id' => $settlement->public_id,
            'batch_no' => $settlement->batch_no,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
            'status' => $settlement->status,
            'submission_count' => (int) $settlement->submission_count,
            'total_commission' => $this->money((int) $settlement->total_commission_cents),
            'total_team_bonus' => $this->money((int) $settlement->total_team_bonus_cents),
            'total_amount' => $this->money((int) $settlement->total_commission_cents + (int) $settlement->total_team_bonus_cents),
            'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
            'paid_at' => $settlement->paid_at?->toIso8601String(),
            'created_at' => $settlement->created_at?->toIso8601String(),
        ];

        if ($withItems) {
            $data['items'] = $settlement->items()->with(['user', 'team'])->get()->map(fn (PushSettlementItem $item) => [
                'id' => $item->id,
                'user_id' => $item->user?->public_id,
                'nickname' => $item->user?->nickname ?: '成员',
                'team_name' => $item->team?->name,
                'level_code' => $item->level_code,
                'submission_count' => (int) $item->submission_count,
                'commission' => $this->money((int) $item->commission_cents),
                'team_bonus' => $this->money((int) $item->team_bonus_cents),
                'status' => $item->status,
            ])->values();
        }

        return $data;
    }

    public function activeMembership(User $user): ?PushTeamMember
    {
        return PushTeamMember::query()
            ->with(['team', 'user'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{identity_verified: bool, campus_matched: bool, no_other_team: bool, pending_application: bool, can_apply: bool}
     */
    public function entryConditions(User $user, PushTeam $team): array
    {
        $identityVerified = UserIdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->exists();
        $campusMatched = $user->campus_id !== null && (int) $user->campus_id === (int) $team->campus_id;
        $membership = $this->activeMembership($user);
        $noOtherTeam = $membership === null || (int) $membership->team_id === (int) $team->id;
        $pendingApplication = PushTeamApplication::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        return [
            'identity_verified' => $identityVerified,
            'campus_matched' => $campusMatched,
            'no_other_team' => $noOtherTeam,
            'pending_application' => $pendingApplication,
            'can_apply' => $identityVerified && $campusMatched && $noOtherTeam && ! $pendingApplication,
        ];
    }

    public function apply(User $user, PushTeam $team, ?string $message): PushTeamApplication
    {
        if ($team->status !== 'recruiting') {
            throw ValidationException::withMessages(['team' => ['该团队当前不在招募中。']]);
        }
        $conditions = $this->entryConditions($user, $team);
        if ($conditions['pending_application']) {
            throw ValidationException::withMessages(['team' => ['已提交申请，请等待审核。']]);
        }
        if (! $conditions['can_apply']) {
            throw ValidationException::withMessages(['team' => ['入团条件未满足：需实名认证、校区一致且未加入其他团队。']]);
        }
        if ((int) $team->member_count >= (int) $team->member_limit) {
            throw ValidationException::withMessages(['team' => ['团队人数已满。']]);
        }

        return PushTeamApplication::query()->create([
            'campus_id' => $team->campus_id,
            'type' => 'join',
            'team_id' => $team->id,
            'user_id' => $user->id,
            'message' => $message,
            'status' => 'pending',
        ]);
    }

    public function applyAsLeader(User $user, ?string $teamName, ?string $message): PushTeamApplication
    {
        $membership = $this->activeMembership($user);
        if ($membership !== null) {
            throw ValidationException::withMessages(['team' => ['已加入地推团队，不能重复申请成为团长。']]);
        }
        $identityVerified = UserIdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->exists();
        if (! $identityVerified) {
            throw ValidationException::withMessages(['identity' => ['申请团长前需完成实名认证。']]);
        }
        $pending = PushTeamApplication::query()
            ->where('user_id', $user->id)
            ->where('type', 'leader')
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            throw ValidationException::withMessages(['application' => ['已提交团长申请，请等待审核。']]);
        }

        return PushTeamApplication::query()->create([
            'campus_id' => $user->campus_id ?? request()->attributes->get('campus_database_id'),
            'type' => 'leader',
            'team_id' => null,
            'user_id' => $user->id,
            'team_name' => $teamName,
            'message' => $message,
            'status' => 'pending',
        ]);
    }

    public function leaveTeam(User $user): void
    {
        $membership = $this->activeMembership($user);
        if ($membership === null) {
            throw ValidationException::withMessages(['team' => ['当前未加入地推团队。']]);
        }
        $team = PushTeam::query()->lockForUpdate()->find($membership->team_id);
        if ($team !== null && (int) $team->leader_user_id === (int) $user->id) {
            throw ValidationException::withMessages(['team' => ['团长不能直接退出团队，请联系运营处理。']]);
        }
        DB::transaction(function () use ($membership, $team) {
            $membership->forceFill(['status' => 'left', 'left_at' => now()])->save();
            if ($team !== null) {
                $team->forceFill(['member_count' => max(0, (int) $team->member_count - 1)])->save();
            }
        });
    }

    public function reviewApplication(?User $leader, ?AdminUser $admin, PushTeamApplication $application, bool $approve, ?string $note): PushTeamApplication
    {
        return DB::transaction(function () use ($leader, $admin, $application, $approve, $note) {
            $locked = PushTeamApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['application' => ['该申请已处理。']]);
            }

            if ($locked->type === 'leader') {
                if ($leader !== null) {
                    throw ValidationException::withMessages(['application' => ['团长申请需由平台运营审核。']]);
                }
                if ($approve) {
                    if ($admin === null) {
                        throw ValidationException::withMessages(['application' => ['缺少审核人。']]);
                    }
                    $existing = PushTeamMember::query()->where('user_id', $locked->user_id)->where('status', 'active')->lockForUpdate()->first();
                    if ($existing !== null) {
                        throw ValidationException::withMessages(['application' => ['该用户已加入其他团队。']]);
                    }
                    $team = PushTeam::query()->create([
                        'campus_id' => $locked->campus_id,
                        'name' => $locked->team_name ?: (($locked->user?->nickname ?: '校园').'地推团队'),
                        'slogan' => '校园地推 · 招募中',
                        'intro' => $locked->message,
                        'leader_user_id' => $locked->user_id,
                        'level_code' => 'A1',
                        'status' => 'recruiting',
                        'member_count' => 1,
                        'stats_month' => now()->format('Y-m'),
                        'created_by_admin_id' => $admin->id,
                    ]);
                    PushTeamMember::query()->create([
                        'campus_id' => $team->campus_id,
                        'team_id' => $team->id,
                        'user_id' => $locked->user_id,
                        'role' => 'leader',
                        'level_code' => 'A1',
                        'status' => 'active',
                        'stats_month' => now()->format('Y-m'),
                        'joined_at' => now(),
                    ]);
                    $locked->forceFill(['team_id' => $team->id])->save();
                }
                $locked->forceFill([
                    'status' => $approve ? 'approved' : 'rejected',
                    'review_note' => $note,
                    'reviewed_by_admin_id' => $admin?->id,
                    'reviewed_at' => now(),
                ])->save();

                return $locked->fresh(['user', 'team']);
            }

            $team = PushTeam::query()->lockForUpdate()->findOrFail($locked->team_id);
            if ($leader !== null && (int) $team->leader_user_id !== (int) $leader->id) {
                throw ValidationException::withMessages(['application' => ['只有团长可以审核入团申请。']]);
            }

            if ($approve) {
                $existing = PushTeamMember::query()->where('user_id', $locked->user_id)->where('status', 'active')->lockForUpdate()->first();
                if ($existing !== null) {
                    throw ValidationException::withMessages(['application' => ['该用户已加入其他团队。']]);
                }
                if ((int) $team->member_count >= (int) $team->member_limit) {
                    throw ValidationException::withMessages(['team' => ['团队人数已满。']]);
                }
                PushTeamMember::query()->updateOrCreate(
                    ['team_id' => $team->id, 'user_id' => $locked->user_id],
                    [
                        'campus_id' => $team->campus_id,
                        'role' => 'member',
                        'level_code' => 'A1',
                        'status' => 'active',
                        'stats_month' => now()->format('Y-m'),
                        'month_orders' => 0,
                        'month_commission_cents' => 0,
                        'joined_at' => now(),
                        'left_at' => null,
                    ],
                );
                $team->increment('member_count');
            }

            $locked->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'review_note' => $note,
                'reviewed_by_user_id' => $leader?->id,
                'reviewed_by_admin_id' => $admin?->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked->fresh(['user', 'team']);
        });
    }

    public function claimPosition(User $user, PushTask $task, ?string $keyword): PushPosition
    {
        if ($task->status !== 'published') {
            throw ValidationException::withMessages(['task' => ['该推广任务未开放。']]);
        }
        $now = now();
        if ($task->starts_at !== null && $task->starts_at->isAfter($now)) {
            throw ValidationException::withMessages(['task' => ['推广任务尚未开始。']]);
        }
        if ($task->ends_at !== null && $task->ends_at->isBefore($now)) {
            throw ValidationException::withMessages(['task' => ['推广任务已结束。']]);
        }
        $membership = $this->activeMembership($user);
        if ($membership === null) {
            throw ValidationException::withMessages(['team' => ['加入地推团队后才能领取推广位。']]);
        }

        return DB::transaction(function () use ($user, $task, $keyword, $membership) {
            $lockedTask = PushTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->quota > 0 && (int) $lockedTask->claimed_count >= (int) $lockedTask->quota) {
                throw ValidationException::withMessages(['task' => ['推广位已领完。']]);
            }
            $used = PushPosition::query()->where('task_id', $lockedTask->id)->where('user_id', $user->id)->count();
            if ($used >= max(1, (int) $lockedTask->per_user_limit)) {
                throw ValidationException::withMessages(['task' => ['该任务的可领推广位已达上限。']]);
            }

            $position = PushPosition::query()->create([
                'campus_id' => $lockedTask->campus_id,
                'task_id' => $lockedTask->id,
                'user_id' => $user->id,
                'team_id' => $membership->team_id,
                'keyword' => $keyword,
                'status' => 'active',
                'claimed_at' => now(),
                'expires_at' => $lockedTask->ends_at ?? now()->addDays(30),
            ]);
            $lockedTask->increment('claimed_count');

            return $position->load('task');
        });
    }

    public function submit(User $user, PushPosition $position, array $data): PushSubmission
    {
        if ((int) $position->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['position' => ['推广位不属于当前用户。']]);
        }
        if ($position->status !== 'active') {
            throw ValidationException::withMessages(['position' => ['推广位已失效。']]);
        }
        $membership = $this->activeMembership($user);
        if ($membership === null || (int) $membership->team_id !== (int) $position->team_id) {
            throw ValidationException::withMessages(['team' => ['当前团队状态异常，无法交单。']]);
        }
        $images = array_values(array_filter((array) ($data['images'] ?? []), fn ($url) => is_string($url) && $url !== ''));
        if ($images === []) {
            throw ValidationException::withMessages(['images' => ['请至少上传一张交单凭证。']]);
        }
        $position->loadMissing('task');
        $task = $position->task;
        if ($task === null || $task->status !== 'published') {
            throw ValidationException::withMessages(['task' => ['推广任务已关闭。']]);
        }

        return PushSubmission::query()->create([
            'campus_id' => $position->campus_id,
            'task_id' => $task->id,
            'position_id' => $position->id,
            'user_id' => $user->id,
            'team_id' => $position->team_id,
            'level_code' => $membership->level_code,
            'images' => $images,
            'remark' => $data['remark'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'status' => 'pending',
        ])->load(['task', 'position']);
    }

    public function reviewSubmission(AdminUser $admin, PushSubmission $submission, bool $approve, ?string $reason): PushSubmission
    {
        return DB::transaction(function () use ($admin, $submission, $approve, $reason) {
            $locked = PushSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['submission' => ['该交单已审核。']]);
            }
            $task = PushTask::query()->findOrFail($locked->task_id);

            if ($approve) {
                $level = $this->level($locked->level_code);
                $commission = (int) round((int) $task->reward_cents * (float) $level->coefficient);
                $teamBonus = (int) round($commission * (float) $level->team_bonus_rate);
                $locked->forceFill([
                    'status' => 'approved',
                    'commission_cents' => $commission,
                    'team_bonus_cents' => $teamBonus,
                    'reject_reason' => null,
                ]);
                $this->bumpStats($locked);
            } else {
                $locked->forceFill([
                    'status' => 'rejected',
                    'reject_reason' => $reason,
                    'commission_cents' => 0,
                    'team_bonus_cents' => 0,
                ]);
            }
            $locked->forceFill(['reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now()])->save();

            return $locked->fresh(['task', 'position', 'user']);
        });
    }

    public function buildSettlement(AdminUser $admin, string $start, string $end, ?int $campusId): PushSettlement
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->endOfDay();
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages(['period' => ['结算结束日期不能早于开始日期。']]);
        }

        return DB::transaction(function () use ($admin, $startDate, $endDate, $campusId) {
            $query = PushSubmission::query()
                ->where('status', 'approved')
                ->whereNull('settlement_id')
                ->whereBetween('reviewed_at', [$startDate, $endDate]);
            if ($campusId !== null) {
                $query->where('campus_id', $campusId);
            }
            $submissions = $query->lockForUpdate()->get();
            if ($submissions->isEmpty()) {
                throw ValidationException::withMessages(['period' => ['所选周期内没有可结算的已通过交单。']]);
            }

            $settlement = PushSettlement::query()->create([
                'campus_id' => $campusId ?? $submissions->first()->campus_id,
                'batch_no' => 'PT'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
                'status' => 'draft',
                'submission_count' => $submissions->count(),
                'total_commission_cents' => (int) $submissions->sum('commission_cents'),
                'total_team_bonus_cents' => (int) $submissions->sum('team_bonus_cents'),
                'created_by_admin_id' => $admin->id,
            ]);

            $items = [];
            foreach ($submissions as $submission) {
                $memberKey = (int) $submission->user_id;
                if (! isset($items[$memberKey])) {
                    $items[$memberKey] = [
                        'campus_id' => $submission->campus_id,
                        'settlement_id' => $settlement->id,
                        'user_id' => $submission->user_id,
                        'team_id' => $submission->team_id,
                        'level_code' => $submission->level_code,
                        'submission_count' => 0,
                        'commission_cents' => 0,
                        'team_bonus_cents' => 0,
                        'status' => 'pending',
                    ];
                }
                $items[$memberKey]['submission_count']++;
                $items[$memberKey]['commission_cents'] += (int) $submission->commission_cents;

                if ((int) $submission->team_bonus_cents > 0 && $submission->team_id !== null) {
                    $leaderId = PushTeam::query()->whereKey($submission->team_id)->value('leader_user_id');
                    if ($leaderId !== null) {
                        $bonusKey = (int) $leaderId;
                        if (! isset($items[$bonusKey])) {
                            $items[$bonusKey] = [
                                'campus_id' => $submission->campus_id,
                                'settlement_id' => $settlement->id,
                                'user_id' => $leaderId,
                                'team_id' => $submission->team_id,
                                'level_code' => $submission->level_code,
                                'submission_count' => 0,
                                'commission_cents' => 0,
                                'team_bonus_cents' => 0,
                                'status' => 'pending',
                            ];
                        }
                        $items[$bonusKey]['team_bonus_cents'] += (int) $submission->team_bonus_cents;
                    }
                }
            }
            $now = now();
            $rows = array_values($items);
            foreach ($rows as &$row) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);
            PushSettlementItem::query()->insert($rows);
            PushSubmission::query()->whereIn('id', $submissions->pluck('id'))->update(['settlement_id' => $settlement->id]);

            return $settlement->fresh();
        });
    }

    public function confirmSettlement(PushSettlement $settlement): PushSettlement
    {
        if ($settlement->status !== 'draft') {
            throw ValidationException::withMessages(['settlement' => ['只有草稿批次可以确认。']]);
        }
        $settlement->forceFill(['status' => 'pending', 'confirmed_at' => now()])->save();

        return $settlement->fresh();
    }

    /**
     * 打款：金额进入现有现金钱包（复用 user_asset_accounts / wallet_transactions），
     * 提现仍走原有提现审核，不新建钱包账户。
     */
    public function paySettlement(AdminUser $admin, PushSettlement $settlement): PushSettlement
    {
        return DB::transaction(function () use ($admin, $settlement) {
            $locked = PushSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if (! in_array($locked->status, ['pending', 'draft'], true)) {
                throw ValidationException::withMessages(['settlement' => ['该批次已打款。']]);
            }
            $items = PushSettlementItem::query()->where('settlement_id', $locked->id)->where('status', 'pending')->lockForUpdate()->get();
            foreach ($items as $item) {
                $amount = (int) $item->commission_cents + (int) $item->team_bonus_cents;
                if ($amount > 0) {
                    $this->creditWallet((int) $item->user_id, $amount, $locked, $admin);
                }
                $item->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            }
            PushSubmission::query()
                ->where('settlement_id', $locked->id)
                ->whereNull('settled_at')
                ->update(['settled_at' => now()]);
            $locked->forceFill(['status' => 'paid', 'paid_at' => now(), 'confirmed_at' => $locked->confirmed_at ?? now()])->save();

            return $locked->fresh();
        });
    }

    private function creditWallet(int $userId, int $amount, PushSettlement $settlement, AdminUser $admin): void
    {
        User::query()->lockForUpdate()->findOrFail($userId);
        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $userId]);
        $account = UserAssetAccount::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
        $after = (int) $account->wallet_available_cents + $amount;
        $account->forceFill(['wallet_available_cents' => $after, 'version' => (int) $account->version + 1])->save();

        WalletTransaction::query()->create([
            'user_id' => $userId,
            'transaction_type' => 'credit',
            'change_available_cents' => $amount,
            'change_frozen_cents' => 0,
            'available_after_cents' => $after,
            'frozen_after_cents' => (int) $account->wallet_frozen_cents,
            'business_type' => 'push_commission',
            'business_id' => $settlement->public_id,
            'event_key_hash' => hash('sha256', 'push-settlement:'.$settlement->public_id.':'.$userId),
            'admin_user_id' => $admin->id,
            'reason' => '地推佣金结算 '.$settlement->batch_no,
        ]);
    }

    private function bumpStats(PushSubmission $submission): void
    {
        $month = now()->format('Y-m');
        if ($submission->team_id !== null) {
            $team = PushTeam::query()->lockForUpdate()->find($submission->team_id);
            if ($team !== null) {
                if ($team->stats_month !== $month) {
                    $team->month_orders = 0;
                    $team->month_commission_cents = 0;
                }
                $team->stats_month = $month;
                $team->month_orders = (int) $team->month_orders + 1;
                $team->month_commission_cents = (int) $team->month_commission_cents + (int) $submission->commission_cents;
                $team->save();
            }
        }
        $member = PushTeamMember::query()
            ->where('user_id', $submission->user_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        if ($member !== null) {
            if ($member->stats_month !== $month) {
                $member->month_orders = 0;
                $member->month_commission_cents = 0;
            }
            $member->stats_month = $month;
            $member->month_orders = (int) $member->month_orders + 1;
            $member->month_commission_cents = (int) $member->month_commission_cents + (int) $submission->commission_cents;
            $member->save();
        }
    }

    /**
     * @return array{month_orders: int, month_commission_cents: int}
     */
    private function teamStats(PushTeam $team): array
    {
        $month = now()->format('Y-m');
        if ($team->stats_month !== $month) {
            return ['month_orders' => 0, 'month_commission_cents' => 0];
        }

        return ['month_orders' => (int) $team->month_orders, 'month_commission_cents' => (int) $team->month_commission_cents];
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
