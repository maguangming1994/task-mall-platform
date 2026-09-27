<?php

namespace App\Modules\Growth\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReferralTeamService
{
    public const MAX_DEPTH = 8;

    public const MAX_MEMBERS = 5000;

    public const MAX_LIST_MEMBERS = 1000;

    private const QUERY_ID_CHUNK_SIZE = 1000;

    /** @param array<string, mixed> $filters */
    public function index(array $filters): array
    {
        $campusId = $this->campusId();
        $query = User::query()->whereExists(function (QueryBuilder $subquery) use ($campusId): void {
            $subquery->selectRaw('1')
                ->from('referrals')
                ->join('users as direct_members', 'direct_members.id', '=', 'referrals.referred_user_id')
                ->whereColumn('referrals.referrer_user_id', 'users.id')
                ->whereNull('referrals.revoked_at');
            if ($campusId !== null) {
                $subquery->where('direct_members.campus_id', $campusId);
            }
        });
        $this->applyLeaderFilters($query, $filters);

        $paginator = $query->latest('users.id')->paginate((int) ($filters['page_size'] ?? 20));
        $leaders = collect($paginator->items());
        $hierarchies = $this->hierarchies(
            $leaders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $campusId,
        );
        $memberIds = collect($hierarchies)
            ->flatMap(fn (array $hierarchy): array => $hierarchy['member_ids'])
            ->unique()
            ->values()
            ->all();
        $performances = $this->performanceByUserIds($memberIds, $campusId);
        $items = $leaders->map(function (User $leader) use ($campusId, $hierarchies, $performances): array {
            $hierarchy = $hierarchies[$leader->id];

            return $this->presentTeam(
                $leader,
                $hierarchy,
                $campusId,
                $this->sumPerformanceForUserIds($hierarchy['member_ids'], $performances),
            );
        })->values();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'metric_scope' => 'descendants_only',
            'metric_definitions' => $this->metricDefinitions(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function show(User $leader, array $filters): array
    {
        $campusId = $this->campusId();
        abort_if($campusId !== null && $leader->campus_id !== $campusId, 404);
        $hierarchy = $this->hierarchy($leader->id, $campusId);
        abort_if($hierarchy['direct_member_count'] === 0, 404);

        $users = User::query()
            ->whereIntegerInRaw('id', $hierarchy['member_ids'])
            ->get()
            ->keyBy('id');
        $nodes = collect($hierarchy['nodes'])
            ->filter(fn (array $node): bool => $users->has($node['user_id']))
            ->filter(fn (array $node): bool => $this->memberMatches($node, $users->get($node['user_id']), $filters))
            ->sortBy([
                ['level', 'asc'],
                ['bound_at', 'asc'],
                ['referral_id', 'asc'],
            ])
            ->values();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $total = $nodes->count();
        $pagedNodes = $nodes->slice(($page - 1) * $pageSize, $pageSize)->values();
        $pagedUserIds = $pagedNodes->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $performances = $this->performanceByUserIds($pagedUserIds, $campusId);
        $parentIds = $pagedNodes->pluck('parent_user_id')->push($leader->id)->unique()->all();
        $parents = User::query()->whereIntegerInRaw('id', $parentIds)->get()->keyBy('id');

        $items = $pagedNodes->map(function (array $node) use ($users, $parents, $performances): array {
            /** @var User $user */
            $user = $users->get($node['user_id']);
            /** @var User|null $parent */
            $parent = $parents->get($node['parent_user_id']);

            return [
                'user' => $this->presentUser($user),
                'parent' => $parent === null ? null : [
                    'id' => $parent->public_id,
                    'nickname' => $parent->nickname,
                ],
                'level' => $node['level'],
                'direct' => $node['level'] === 1,
                'effective' => $this->nodeIsEffective($node),
                'referral_status' => $node['referral_status'],
                'joined_at' => $this->dateTime($node['bound_at']),
                'performance' => $performances[$user->id] ?? $this->emptyPerformance(),
            ];
        })->values();

        return [
            'team' => $this->presentTeam($leader, $hierarchy, $campusId),
            'members' => [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $pageSize)),
                ],
            ],
            'metric_scope' => 'descendants_only',
            'metric_definitions' => $this->metricDefinitions(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyLeaderFilters(Builder $query, array $filters): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $subquery) use ($keyword): void {
                $subquery->where('public_id', 'like', "%{$keyword}%")
                    ->orWhere('nickname', 'like', "%{$keyword}%");
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * The graph is traversed in bounded breadth-first batches. The visited set
     * handles corrupt cycles while the limits keep unexpected fan-out finite.
     *
     * @return array<string, mixed>
     */
    private function hierarchy(int $leaderId, ?int $campusId, int $memberLimit = self::MAX_MEMBERS): array
    {
        $visited = [$leaderId => true];
        $frontier = [$leaderId];
        $nodes = [];
        $truncated = false;

        for ($level = 1; $level <= self::MAX_DEPTH && $frontier !== []; $level++) {
            $remaining = $memberLimit - count($nodes);
            if ($remaining <= 0) {
                $truncated = $this->hasUnseenChild($frontier, $campusId, $visited);
                $frontier = [];
                break;
            }
            $rows = $this->edgeQuery($frontier, $campusId)
                ->whereIntegerNotInRaw('referrals.referred_user_id', array_keys($visited))
                ->limit($remaining + 1)
                ->get();
            if ($rows->count() > $remaining) {
                $truncated = true;
                $rows = $rows->take($remaining);
            }
            $newFrontier = [];
            foreach ($rows as $row) {
                $memberId = (int) $row->referred_user_id;
                $visited[$memberId] = true;
                $newFrontier[] = $memberId;
                $nodes[$memberId] = [
                    'user_id' => $memberId,
                    'parent_user_id' => (int) $row->referrer_user_id,
                    'referral_id' => (string) $row->referral_public_id,
                    'level' => $level,
                    'referral_status' => (string) $row->referral_status,
                    'qualified_at' => $row->qualified_at,
                    'bound_at' => $row->bound_at,
                    'updated_at' => $row->referral_updated_at,
                    'user_status' => (string) $row->user_status,
                ];
            }
            $frontier = array_values(array_unique($newFrontier));
            if ($truncated) {
                break;
            }
        }

        if (! $truncated && $frontier !== [] && $this->hasUnseenChild($frontier, $campusId, $visited)) {
            $truncated = true;
        }

        return $this->summarizeHierarchy($nodes, $truncated, $memberLimit);
    }

    /**
     * Traverses every leader on the page together so list query volume grows by
     * depth, not by the number of teams. Each root still owns its visited set.
     *
     * @param  array<int, int>  $leaderIds
     * @return array<int, array<string, mixed>>
     */
    private function hierarchies(array $leaderIds, ?int $campusId): array
    {
        $memberLimit = self::MAX_LIST_MEMBERS;
        $states = [];
        foreach (array_values(array_unique($leaderIds)) as $leaderId) {
            $states[$leaderId] = [
                'visited' => [$leaderId => true],
                'frontier' => [$leaderId],
                'nodes' => [],
                'truncated' => false,
            ];
        }

        for ($level = 1; $level <= self::MAX_DEPTH; $level++) {
            $parentIds = collect($states)
                ->filter(fn (array $state): bool => ! $state['truncated'] && $state['frontier'] !== [] && count($state['nodes']) < $memberLimit)
                ->flatMap(fn (array $state): array => $state['frontier'])
                ->unique()
                ->values()
                ->all();
            if ($parentIds === []) {
                break;
            }

            $rowLimit = collect($states)->sum(fn (array $state): int => max(0, $memberLimit - count($state['nodes'])));
            [$edgeRows, $edgeLoadTruncated] = $this->edgeRowsForParents($parentIds, $campusId, max(1, $rowLimit));
            if ($edgeLoadTruncated) {
                return $this->individualHierarchies($leaderIds, $campusId, $memberLimit);
            }
            $edgesByParent = $edgeRows->groupBy('referrer_user_id');
            foreach ($states as &$state) {
                if ($state['truncated'] || $state['frontier'] === [] || count($state['nodes']) >= $memberLimit) {
                    continue;
                }
                $newFrontier = [];
                foreach ($state['frontier'] as $parentId) {
                    foreach ($edgesByParent->get($parentId, collect()) as $row) {
                        $memberId = (int) $row->referred_user_id;
                        if (isset($state['visited'][$memberId])) {
                            continue;
                        }
                        if (count($state['nodes']) >= $memberLimit) {
                            $state['truncated'] = true;
                            break 2;
                        }
                        $state['visited'][$memberId] = true;
                        $newFrontier[] = $memberId;
                        $state['nodes'][$memberId] = $this->nodeFromEdge($row, $level);
                    }
                }
                $state['frontier'] = array_values(array_unique($newFrontier));
            }
            unset($state);
        }

        foreach ($states as &$state) {
            if (! $state['truncated'] && $state['frontier'] !== [] && $this->hasUnseenChild($state['frontier'], $campusId, $state['visited'])) {
                $state['truncated'] = true;
            }
        }
        unset($state);

        $hierarchies = [];
        foreach ($states as $leaderId => $state) {
            $hierarchies[$leaderId] = $this->summarizeHierarchy($state['nodes'], $state['truncated'], $memberLimit);
        }

        return $hierarchies;
    }

    /**
     * @param  array<int, int>  $leaderIds
     * @return array<int, array<string, mixed>>
     */
    private function individualHierarchies(array $leaderIds, ?int $campusId, int $memberLimit): array
    {
        $hierarchies = [];
        foreach ($leaderIds as $leaderId) {
            $hierarchies[$leaderId] = $this->hierarchy($leaderId, $campusId, $memberLimit);
        }

        return $hierarchies;
    }

    /**
     * @param  array<int, int>  $parentIds
     * @return array{Collection<int, object>, bool}
     */
    private function edgeRowsForParents(array $parentIds, ?int $campusId, int $rowLimit): array
    {
        $rows = collect();
        foreach (array_chunk(array_values(array_unique($parentIds)), self::QUERY_ID_CHUNK_SIZE) as $parentChunk) {
            $remaining = $rowLimit - $rows->count();
            if ($remaining <= 0) {
                return [$rows, true];
            }
            $batch = $this->edgeQuery($parentChunk, $campusId)->limit($remaining + 1)->get();
            if ($batch->count() > $remaining) {
                return [$rows->concat($batch->take($remaining))->values(), true];
            }
            $rows = $rows->concat($batch)->values();
        }

        return [$rows, false];
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function summarizeHierarchy(array $nodes, bool $truncated, int $memberLimit): array
    {
        $nodeValues = array_values($nodes);

        return [
            'nodes' => $nodeValues,
            'member_ids' => array_keys($nodes),
            'direct_member_count' => count(array_filter($nodeValues, fn (array $node): bool => $node['level'] === 1)),
            'member_count' => count($nodeValues),
            'active_member_count' => count(array_filter($nodeValues, fn (array $node): bool => $node['user_status'] === 'active')),
            'effective_member_count' => count(array_filter($nodeValues, fn (array $node): bool => $this->nodeIsEffective($node))),
            'max_depth' => $nodeValues === [] ? 0 : max(array_column($nodeValues, 'level')),
            'truncated' => $truncated,
            'member_limit' => $memberLimit,
        ];
    }

    /** @return array<string, mixed> */
    private function nodeFromEdge(object $row, int $level): array
    {
        return [
            'user_id' => (int) $row->referred_user_id,
            'parent_user_id' => (int) $row->referrer_user_id,
            'referral_id' => (string) $row->referral_public_id,
            'level' => $level,
            'referral_status' => (string) $row->referral_status,
            'qualified_at' => $row->qualified_at,
            'bound_at' => $row->bound_at,
            'updated_at' => $row->referral_updated_at,
            'user_status' => (string) $row->user_status,
        ];
    }

    /** @param array<int, int> $frontier */
    private function edgeQuery(array $frontier, ?int $campusId): QueryBuilder
    {
        $query = DB::table('referrals')
            ->join('users as member_users', 'member_users.id', '=', 'referrals.referred_user_id')
            ->whereIntegerInRaw('referrals.referrer_user_id', $frontier)
            ->whereNull('referrals.revoked_at')
            ->orderBy('referrals.id')
            ->select([
                'referrals.public_id as referral_public_id',
                'referrals.referrer_user_id',
                'referrals.referred_user_id',
                'referrals.status as referral_status',
                'referrals.qualified_at',
                'referrals.bound_at',
                'referrals.updated_at as referral_updated_at',
                'member_users.status as user_status',
            ]);
        if ($campusId !== null) {
            $query->where('member_users.campus_id', $campusId);
        }

        return $query;
    }

    /** @param array<int, bool> $visited */
    private function hasUnseenChild(array $frontier, ?int $campusId, array $visited): bool
    {
        return $this->edgeQuery($frontier, $campusId)
            ->whereIntegerNotInRaw('referrals.referred_user_id', array_keys($visited))
            ->exists();
    }

    /** @param array<string, mixed> $hierarchy */
    private function presentTeam(User $leader, array $hierarchy, ?int $campusId, ?array $performance = null): array
    {
        $updatedAt = $leader->updated_at === null ? null : CarbonImmutable::instance($leader->updated_at);
        foreach ($hierarchy['nodes'] as $node) {
            $candidate = $node['updated_at'] === null ? null : CarbonImmutable::parse($node['updated_at']);
            if ($candidate !== null && ($updatedAt === null || $candidate->greaterThan($updatedAt))) {
                $updatedAt = $candidate;
            }
        }

        return [
            'id' => $leader->public_id,
            'leader' => $this->presentUser($leader),
            'direct_member_count' => $hierarchy['direct_member_count'],
            'member_count' => $hierarchy['member_count'],
            'active_member_count' => $hierarchy['active_member_count'],
            'effective_member_count' => $hierarchy['effective_member_count'],
            'max_depth' => $hierarchy['max_depth'],
            'performance' => $performance ?? $this->aggregatePerformance($hierarchy['member_ids'], $campusId),
            'metric_scope' => 'descendants_only',
            'updated_at' => $updatedAt?->toIso8601String(),
            'traversal' => [
                'truncated' => $hierarchy['truncated'],
                'max_depth_limit' => self::MAX_DEPTH,
                'member_limit' => $hierarchy['member_limit'],
            ],
        ];
    }

    /** @param array<string, mixed> $node */
    private function nodeIsEffective(array $node): bool
    {
        return $node['user_status'] === 'active' && $node['qualified_at'] !== null;
    }

    /** @param array<string, mixed> $node @param array<string, mixed> $filters */
    private function memberMatches(array $node, User $user, array $filters): bool
    {
        if (($filters['status'] ?? '') !== '' && $user->status !== $filters['status']) {
            return false;
        }
        if (isset($filters['level']) && $node['level'] !== (int) $filters['level']) {
            return false;
        }
        if (array_key_exists('effective', $filters) && $this->nodeIsEffective($node) !== (bool) $filters['effective']) {
            return false;
        }
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        if ($keyword === '') {
            return true;
        }

        return str_contains(mb_strtolower($user->public_id), $keyword)
            || str_contains(mb_strtolower($user->nickname), $keyword);
    }

    /** @param array<int, int> $userIds */
    private function aggregatePerformance(array $userIds, ?int $campusId): array
    {
        return $this->sumPerformances($this->performanceByUserIds($userIds, $campusId));
    }

    /** @param array<int, int> $userIds @return array<int, array<string, int>> */
    private function performanceByUserIds(array $userIds, ?int $campusId): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $result = [];
        foreach ($userIds as $userId) {
            $result[$userId] = $this->emptyPerformance();
        }
        if ($userIds === []) {
            return $result;
        }

        $claims = $this->chunkedRows($userIds, fn (array $ids): Collection => DB::table('task_claims')
            ->join('tasks', 'tasks.id', '=', 'task_claims.task_id')
            ->whereIntegerInRaw('task_claims.user_id', $ids)
            ->where('task_claims.status', 'approved')
            ->whereNotNull('task_claims.approved_at')
            ->when($campusId !== null, fn (QueryBuilder $query) => $query->where('tasks.campus_id', $campusId))
            ->groupBy('task_claims.user_id')
            ->selectRaw('task_claims.user_id, COUNT(*) as aggregate_count')
            ->get());
        foreach ($claims as $row) {
            $result[(int) $row->user_id]['approved_tasks'] = (int) $row->aggregate_count;
        }

        $rewards = $this->chunkedRows($userIds, fn (array $ids): Collection => DB::table('task_reward_deliveries')
            ->join('task_claims', 'task_claims.id', '=', 'task_reward_deliveries.task_claim_id')
            ->join('tasks', 'tasks.id', '=', 'task_claims.task_id')
            ->whereIntegerInRaw('task_claims.user_id', $ids)
            ->where('task_reward_deliveries.status', 'delivered')
            ->whereNotNull('task_reward_deliveries.delivered_at')
            ->when($campusId !== null, fn (QueryBuilder $query) => $query->where('tasks.campus_id', $campusId))
            ->groupBy('task_claims.user_id', 'task_reward_deliveries.asset_type')
            ->selectRaw('task_claims.user_id, task_reward_deliveries.asset_type, COALESCE(SUM(task_reward_deliveries.amount), 0) as aggregate_amount')
            ->get());
        foreach ($rewards as $row) {
            $field = match ($row->asset_type) {
                'points' => 'task_reward_points',
                'wallet_cash' => 'task_reward_wallet_cents',
                'coupon' => 'task_reward_coupons',
                default => null,
            };
            if ($field !== null) {
                $result[(int) $row->user_id][$field] = (int) $row->aggregate_amount;
            }
        }

        $grossExpression = '(paid_wallet_amount + paid_external_amount)';
        $refundExpression = '(refunded_wallet_amount + refunded_external_amount)';
        $netExpression = "CASE WHEN {$grossExpression} > {$refundExpression} THEN {$grossExpression} - {$refundExpression} ELSE 0 END";
        $orders = $this->chunkedRows($userIds, fn (array $ids): Collection => DB::table('orders')
            ->whereIntegerInRaw('user_id', $ids)
            ->whereNotNull('completed_at')
            ->whereRaw("{$grossExpression} > 0")
            ->when($campusId !== null, fn (QueryBuilder $query) => $query->where('campus_id', $campusId))
            ->groupBy('user_id')
            ->selectRaw("user_id, COUNT(*) as paid_orders, COALESCE(SUM({$grossExpression}), 0) as gross_amount, COALESCE(SUM({$refundExpression}), 0) as refunded_amount, COALESCE(SUM({$netExpression}), 0) as net_amount")
            ->get());
        foreach ($orders as $row) {
            $userId = (int) $row->user_id;
            $result[$userId]['paid_orders'] = (int) $row->paid_orders;
            $result[$userId]['paid_order_gross_amount_cents'] = (int) $row->gross_amount;
            $result[$userId]['paid_order_refunded_amount_cents'] = (int) $row->refunded_amount;
            $result[$userId]['paid_order_net_amount_cents'] = (int) $row->net_amount;
            $result[$userId]['paid_order_amount_cents'] = (int) $row->net_amount;
        }

        $referralRewards = $this->chunkedRows($userIds, fn (array $ids): Collection => DB::table('point_transactions')
            ->join('referrals', 'referrals.public_id', '=', 'point_transactions.source_business_id')
            ->join('users as invited_users', 'invited_users.id', '=', 'referrals.referred_user_id')
            ->whereIntegerInRaw('point_transactions.user_id', $ids)
            ->where('point_transactions.source_code', 'referral_new_user')
            ->where('point_transactions.change_available', '>', 0)
            ->when($campusId !== null, fn (QueryBuilder $query) => $query->where('invited_users.campus_id', $campusId))
            ->groupBy('point_transactions.user_id')
            ->selectRaw('point_transactions.user_id, COUNT(*) as reward_count, COALESCE(SUM(point_transactions.change_available), 0) as reward_points')
            ->get());
        foreach ($referralRewards as $row) {
            $userId = (int) $row->user_id;
            $result[$userId]['referral_reward_count'] = (int) $row->reward_count;
            $result[$userId]['referral_reward_points'] = (int) $row->reward_points;
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  callable(array<int, int>): Collection<int, object>  $load
     * @return Collection<int, object>
     */
    private function chunkedRows(array $userIds, callable $load): Collection
    {
        $rows = collect();
        foreach (array_chunk($userIds, self::QUERY_ID_CHUNK_SIZE) as $chunk) {
            $rows = $rows->concat($load($chunk))->values();
        }

        return $rows;
    }

    /** @param array<int, array<string, int>> $performances */
    private function sumPerformances(array $performances): array
    {
        $totals = $this->emptyPerformance();
        foreach ($performances as $performance) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($performance[$key] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, array<string, int>>  $performances
     */
    private function sumPerformanceForUserIds(array $userIds, array $performances): array
    {
        $selected = [];
        foreach ($userIds as $userId) {
            $selected[$userId] = $performances[$userId] ?? $this->emptyPerformance();
        }

        return $this->sumPerformances($selected);
    }

    /** @return array<string, int> */
    private function emptyPerformance(): array
    {
        return [
            'approved_tasks' => 0,
            'task_reward_points' => 0,
            'task_reward_wallet_cents' => 0,
            'task_reward_coupons' => 0,
            'paid_orders' => 0,
            'paid_order_gross_amount_cents' => 0,
            'paid_order_refunded_amount_cents' => 0,
            'paid_order_net_amount_cents' => 0,
            'paid_order_amount_cents' => 0,
            'referral_reward_count' => 0,
            'referral_reward_points' => 0,
        ];
    }

    /** @return array<string, string> */
    private function metricDefinitions(): array
    {
        return [
            'effective_member' => 'active_user_with_qualified_non_revoked_referral',
            'approved_tasks' => 'approved_task_claims',
            'task_rewards' => 'delivered_task_reward_deliveries_by_asset',
            'paid_orders' => 'completed_orders_with_positive_cash_payment',
            'paid_order_amount' => 'gross_cash_payment_minus_refunded_cash_not_below_zero',
            'referral_rewards' => 'descendant_ledgered_positive_available_referral_new_user_points',
        ];
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'phone_masked' => $this->maskPhone($user->phone),
            'status' => $user->status,
        ];
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches) ? $matches[1].'****'.$matches[2] : '***';
    }

    private function campusId(): ?int
    {
        if (! app()->bound('request') || request()->attributes->get('campus_context_strict') !== true) {
            return null;
        }
        $campusId = request()->attributes->get('campus_database_id');

        return is_int($campusId) ? $campusId : null;
    }

    private function dateTime(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
