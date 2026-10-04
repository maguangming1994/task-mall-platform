<?php

namespace App\Modules\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OperationsDashboardService
{
    private const TASK_CATEGORY_LABELS = [
        'onboarding' => '新手任务',
        'daily' => '日常任务',
        'community' => '社区任务',
        'commerce' => '商城任务',
        'research' => '调研任务',
        'campaign' => '活动任务',
    ];

    public function metrics(): array
    {
        return [
            'users' => ['active_total' => DB::table('users')->where('status', 'active')->count(), 'new_today' => DB::table('users')->whereDate('created_at', today())->count()],
            'tasks' => ['published' => DB::table('tasks')->where('status', 'published')->count(), 'pending_reviews' => DB::table('task_submissions')->whereIn('status', ['submitted', 'reviewing'])->count()],
            'orders' => ['paid_today' => DB::table('orders')->whereDate('paid_at', today())->count(), 'paid_cash_today' => (int) DB::table('orders')->whereDate('paid_at', today())->sum(DB::raw('paid_wallet_amount + paid_external_amount')), 'pending_fulfillment' => DB::table('orders')->where('status', 'pending_fulfillment')->count()],
            'after_sales' => ['pending_refunds' => DB::table('refunds')->whereIn('status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing'])->count(), 'pending_exchanges' => DB::table('exchanges')->whereNotIn('status', ['completed', 'rejected', 'closed'])->count()],
            'community' => ['published_posts' => DB::table('community_posts')->where('status', 'published')->count(), 'approved_activities' => DB::table('community_activities')->where('status', 'approved')->count(), 'pending_reviews' => DB::table('community_post_versions')->where('review_status', 'pending')->count() + DB::table('community_comments')->where('status', 'pending_review')->count() + DB::table('community_activities')->where('status', 'pending_review')->count(), 'open_reports' => DB::table('content_reports')->where('status', 'submitted')->count()],
            'finance' => ['reconciliation_differences' => DB::table('reconciliation_entries')->where('result', '!=', 'matched')->where('resolution_status', 'pending')->count(), 'pending_withdrawals' => DB::table('wallet_withdrawals')->whereIn('status', ['submitted', 'reviewing'])->count()],
            'notifications' => ['unread_total' => DB::table('notifications')->whereNull('read_at')->count(), 'failed_deliveries' => DB::table('notification_deliveries')->where('status', 'failed')->count(), 'retrying_deliveries' => DB::table('notification_deliveries')->where('status', 'retrying')->count()],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function analytics(): array
    {
        $today = CarbonImmutable::today();
        $weekStart = $today->subDays(6);

        $dauByDate = DB::table('user_devices')
            ->whereNull('revoked_at')
            ->where('last_active_at', '>=', $weekStart->startOfDay())
            ->selectRaw('DATE(last_active_at) as day, COUNT(DISTINCT user_id) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $newUsersByDate = DB::table('users')
            ->where('created_at', '>=', $weekStart->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $claimsByDate = DB::table('task_claims')
            ->where('created_at', '>=', $weekStart->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $ordersByDate = DB::table('orders')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $weekStart->startOfDay())
            ->selectRaw('DATE(paid_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $dauTrend = [];
        $newUserTrend = [];
        $taskClaimTrend = [];
        $orderTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->subDays($i);
            $key = $date->toDateString();
            $label = $date->format('m-d');
            $dauTrend[] = ['date' => $label, 'count' => (int) ($dauByDate[$key] ?? 0)];
            $newUserTrend[] = ['date' => $label, 'count' => (int) ($newUsersByDate[$key] ?? 0)];
            $taskClaimTrend[] = ['date' => $label, 'count' => (int) ($claimsByDate[$key] ?? 0)];
            $orderTrend[] = ['date' => $label, 'count' => (int) ($ordersByDate[$key] ?? 0)];
        }

        $dauToday = $dauTrend[6]['count'];
        $dauYesterday = $dauTrend[5]['count'];
        $newUsersToday = $newUserTrend[6]['count'];
        $newUsersYesterday = $newUserTrend[5]['count'];

        $pointsToday = (int) DB::table('task_reward_deliveries')->where('asset_type', 'points')->where('status', 'delivered')->whereDate('delivered_at', $today)->sum('amount');
        $pointsYesterday = (int) DB::table('task_reward_deliveries')->where('asset_type', 'points')->where('status', 'delivered')->whereDate('delivered_at', $today->subDay())->sum('amount');
        $gmvToday = (int) DB::table('orders')->whereDate('paid_at', $today)->sum(DB::raw('paid_wallet_amount + paid_external_amount'));
        $gmvYesterday = (int) DB::table('orders')->whereDate('paid_at', $today->subDay())->sum(DB::raw('paid_wallet_amount + paid_external_amount'));

        $viewTotal = (int) DB::table('tasks')->whereNotNull('published_version_no')->sum('view_count');
        $claimTotal = (int) DB::table('task_claims')->count();
        $submissionTotal = (int) DB::table('task_submissions')->count();
        $approvedTotal = (int) DB::table('task_reviews')->where('decision', 'approved')->count();
        $funnelBase = max($viewTotal, 1);
        $funnel = [
            ['label' => '浏览任务', 'count' => $viewTotal, 'ratio' => 100.0],
            ['label' => '领取任务', 'count' => $claimTotal, 'ratio' => round($claimTotal / $funnelBase * 100, 1)],
            ['label' => '提交成果', 'count' => $submissionTotal, 'ratio' => round($submissionTotal / $funnelBase * 100, 1)],
            ['label' => '完成验收', 'count' => $approvedTotal, 'ratio' => round($approvedTotal / $funnelBase * 100, 1)],
        ];

        $categoryRows = DB::table('tasks')
            ->join('task_versions', function ($join): void {
                $join->on('task_versions.task_id', '=', 'tasks.id')->on('task_versions.version_no', '=', 'tasks.published_version_no');
            })
            ->selectRaw("task_versions.content->>'category' as category")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');
        $categoryTotal = max((int) $categoryRows->sum(), 1);
        $taskTypeShare = $categoryRows->map(fn ($count, $category) => [
            'label' => self::TASK_CATEGORY_LABELS[$category] ?? '其他',
            'count' => (int) $count,
            'ratio' => round((int) $count / $categoryTotal * 100, 1),
        ])->sortByDesc('count')->values()->all();

        $campusNames = DB::table('campuses')->pluck('name', 'id');
        $campusUsers = DB::table('users')->whereNotNull('campus_id')->selectRaw('campus_id, COUNT(*) as total')->groupBy('campus_id')->pluck('total', 'campus_id');
        $campusDau = DB::table('user_devices')
            ->join('users', 'users.id', '=', 'user_devices.user_id')
            ->whereNull('user_devices.revoked_at')
            ->whereNotNull('users.campus_id')
            ->whereDate('user_devices.last_active_at', $today)
            ->selectRaw('users.campus_id, COUNT(DISTINCT user_devices.user_id) as total')
            ->groupBy('users.campus_id')
            ->pluck('total', 'users.campus_id');
        $campusTasks = DB::table('tasks')->whereNotNull('campus_id')->whereIn('status', ['published', 'scheduled', 'paused'])->selectRaw('campus_id, COUNT(*) as total')->groupBy('campus_id')->pluck('total', 'campus_id');
        $campusTop5 = collect($campusNames)
            ->map(fn ($name, $id) => [
                'name' => (string) $name,
                'dau' => (int) ($campusDau[$id] ?? 0),
                'user_total' => (int) ($campusUsers[$id] ?? 0),
                'task_count' => (int) ($campusTasks[$id] ?? 0),
            ])
            ->sortByDesc(fn (array $row) => [$row['dau'], $row['user_total']])
            ->take(5)
            ->map(fn (array $row) => ['name' => $row['name'], 'dau' => $row['dau'], 'task_count' => $row['task_count']])
            ->values()
            ->all();

        $alerts = [];
        $pendingWithdrawals = DB::table('wallet_withdrawals')->whereIn('status', ['submitted', 'reviewing']);
        $withdrawalCount = (clone $pendingWithdrawals)->count();
        if ($withdrawalCount > 0) {
            $alerts[] = ['level' => 'critical', 'text' => "提现待审积压 {$withdrawalCount} 笔", 'at' => (clone $pendingWithdrawals)->max('created_at')];
        }
        $lowStock = DB::table('inventories')
            ->join('product_skus', 'product_skus.id', '=', 'inventories.product_sku_id')
            ->where('product_skus.status', 'active')
            ->whereNotNull('product_skus.low_stock_threshold')
            ->whereRaw('(inventories.on_hand_quantity - inventories.reserved_quantity) <= product_skus.low_stock_threshold')
            ->orderBy('inventories.updated_at', 'desc')
            ->limit(3)
            ->get(['product_skus.name', 'inventories.updated_at']);
        foreach ($lowStock as $sku) {
            $alerts[] = ['level' => 'warning', 'text' => "「{$sku->name}」库存预警", 'at' => $sku->updated_at];
        }
        $pendingAppeals = DB::table('task_appeals')->where('status', 'submitted');
        $appealCount = (clone $pendingAppeals)->count();
        if ($appealCount > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "任务纠纷待处理 {$appealCount} 起", 'at' => (clone $pendingAppeals)->max('created_at')];
        }
        $openReports = DB::table('content_reports')->where('status', 'submitted');
        $reportCount = (clone $openReports)->count();
        if ($reportCount > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "用户举报待处理 {$reportCount} 条", 'at' => (clone $openReports)->max('created_at')];
        }
        if ($alerts === []) {
            $alerts[] = ['level' => 'info', 'text' => '系统运行正常', 'at' => now()->toIso8601String()];
        }

        return [
            'today' => [
                'dau' => $dauToday,
                'dau_change_pct' => $this->changePct($dauToday, $dauYesterday),
                'new_users' => $newUsersToday,
                'new_users_change_pct' => $this->changePct($newUsersToday, $newUsersYesterday),
                'task_points_turnover' => $pointsToday,
                'task_points_change_pct' => $this->changePct($pointsToday, $pointsYesterday),
                'mall_gmv_cents' => $gmvToday,
                'mall_gmv_change_pct' => $this->changePct($gmvToday, $gmvYesterday),
            ],
            'dau_trend' => $dauTrend,
            'new_user_trend' => $newUserTrend,
            'task_claim_trend' => $taskClaimTrend,
            'order_trend' => $orderTrend,
            'funnel' => $funnel,
            'task_type_share' => $taskTypeShare,
            'campus_top5' => $campusTop5,
            'alerts' => $alerts,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function changePct(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
