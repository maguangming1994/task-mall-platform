<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->index(['referrer_user_id', 'revoked_at', 'id'], 'referrals_team_traversal_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'completed_at'], 'orders_user_completed_at_index');
        });
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->index(['user_id', 'source_code', 'source_business_id'], 'point_transactions_user_source_business_index');
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->dropIndex('point_transactions_user_source_business_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_completed_at_index');
        });
        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropIndex('referrals_team_traversal_index');
        });
    }
};
