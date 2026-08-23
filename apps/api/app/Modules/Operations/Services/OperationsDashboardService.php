<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;

class OperationsDashboardService
{
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
}
