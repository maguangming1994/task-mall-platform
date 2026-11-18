<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 等级与佣金规则为平台级配置，不按校区拆分
        Schema::create('push_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 48);
            $table->decimal('coefficient', 6, 3)->default(1.000);
            $table->decimal('team_bonus_rate', 6, 3)->default(0.000);
            $table->unsignedInteger('min_monthly_orders')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('push_levels')->insert([
            ['code' => 'A1', 'name' => 'A1 新手', 'coefficient' => 1.000, 'team_bonus_rate' => 0.000, 'min_monthly_orders' => 0, 'sort_order' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'A2', 'name' => 'A2 熟练', 'coefficient' => 1.100, 'team_bonus_rate' => 0.030, 'min_monthly_orders' => 30, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'A3', 'name' => 'A3 核心', 'coefficient' => 1.200, 'team_bonus_rate' => 0.050, 'min_monthly_orders' => 80, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('push_teams', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('slogan', 200)->nullable();
            $table->text('intro')->nullable();
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('level_code', 16)->default('A1')->index();
            $table->string('status', 16)->default('recruiting')->index();
            $table->string('settlement_cycle', 16)->default('monthly');
            $table->unsignedInteger('review_hours')->default(2);
            $table->unsignedInteger('member_limit')->default(200);
            $table->unsignedInteger('member_count')->default(0);
            $table->char('stats_month', 7)->nullable();
            $table->unsignedInteger('month_orders')->default(0);
            $table->unsignedBigInteger('month_commission_cents')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('push_team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->foreignId('team_id')->constrained('push_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 16)->default('member')->index();
            $table->string('level_code', 16)->default('A1');
            $table->string('status', 16)->default('active')->index();
            $table->char('stats_month', 7)->nullable();
            $table->unsignedInteger('month_orders')->default(0);
            $table->unsignedBigInteger('month_commission_cents')->default(0);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('push_team_applications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('type', 16)->default('join')->index();
            $table->foreignId('team_id')->nullable()->constrained('push_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('team_name', 120)->nullable();
            $table->string('message', 300)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('review_note', 300)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('push_tasks', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('cover_text', 24)->nullable();
            $table->unsignedBigInteger('reward_cents');
            $table->unsignedInteger('quota')->default(0);
            $table->unsignedInteger('claimed_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('push_positions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->foreignId('task_id')->constrained('push_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('push_teams')->nullOnDelete();
            $table->string('keyword', 120)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestampTz('claimed_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['task_id', 'status']);
        });

        Schema::create('push_settlements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('batch_no', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('submission_count')->default(0);
            $table->unsignedBigInteger('total_commission_cents')->default(0);
            $table->unsignedBigInteger('total_team_bonus_cents')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('push_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->foreignId('task_id')->constrained('push_tasks')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('push_positions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('push_teams')->nullOnDelete();
            $table->string('level_code', 16)->default('A1');
            $table->json('images')->nullable();
            $table->string('remark', 500)->nullable();
            $table->string('contact_name', 60)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('reject_reason', 300)->nullable();
            $table->unsignedBigInteger('commission_cents')->default(0);
            $table->unsignedBigInteger('team_bonus_cents')->default(0);
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained('push_settlements')->nullOnDelete();
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'settlement_id']);
        });

        Schema::create('push_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->foreignId('settlement_id')->constrained('push_settlements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('push_teams')->nullOnDelete();
            $table->string('level_code', 16)->default('A1');
            $table->unsignedInteger('submission_count')->default(0);
            $table->unsignedBigInteger('commission_cents')->default(0);
            $table->unsignedBigInteger('team_bonus_cents')->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->index(['settlement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_settlement_items');
        Schema::dropIfExists('push_submissions');
        Schema::dropIfExists('push_settlements');
        Schema::dropIfExists('push_positions');
        Schema::dropIfExists('push_tasks');
        Schema::dropIfExists('push_team_applications');
        Schema::dropIfExists('push_team_members');
        Schema::dropIfExists('push_teams');
        Schema::dropIfExists('push_levels');
    }
};
