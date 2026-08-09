<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'created_at'], 'idx_orders_user_status_created');
            $table->index(['status', 'paid_at'], 'idx_orders_status_paid');
        });
        Schema::table('refunds', fn (Blueprint $table) => $table->index(['user_id', 'status', 'created_at'], 'idx_refunds_user_status_created'));
        Schema::table('task_submissions', fn (Blueprint $table) => $table->index(['status', 'review_due_at'], 'idx_task_submissions_status_due'));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->index(['status', 'next_retry_at'], 'idx_notification_delivery_due'));
        Schema::table('notification_campaigns', fn (Blueprint $table) => $table->index(['status', 'scheduled_at'], 'idx_notification_campaign_due'));
        Schema::table('content_reports', fn (Blueprint $table) => $table->index(['status', 'created_at'], 'idx_content_reports_status_created'));
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_user_status_created');
            $table->dropIndex('idx_orders_status_paid');
        });
        Schema::table('refunds', fn (Blueprint $table) => $table->dropIndex('idx_refunds_user_status_created'));
        Schema::table('task_submissions', fn (Blueprint $table) => $table->dropIndex('idx_task_submissions_status_due'));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropIndex('idx_notification_delivery_due'));
        Schema::table('notification_campaigns', fn (Blueprint $table) => $table->dropIndex('idx_notification_campaign_due'));
        Schema::table('content_reports', fn (Blueprint $table) => $table->dropIndex('idx_content_reports_status_created'));
    }
};
