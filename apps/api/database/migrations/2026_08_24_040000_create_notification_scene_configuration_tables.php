<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->foreignId('user_identity_id')->nullable()->after('user_device_id')->constrained('user_identities')->nullOnDelete();
        });

        Schema::create('notification_scene_configs', function (Blueprint $table) {
            $table->id();
            $table->string('scene_code', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('channels');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_business_events', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scene_code', 64)->index();
            $table->char('event_key_hash', 64)->unique();
            $table->json('variables');
            $table->json('data')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wechat_subscription_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_identity_id')->constrained('user_identities')->cascadeOnDelete();
            $table->char('provider_app_id_hash', 64);
            $table->string('provider_template_id', 160);
            $table->string('status', 24)->default('rejected')->index();
            $table->unsignedInteger('available_count')->default(0);
            $table->timestampTz('last_requested_at')->nullable();
            $table->timestampTz('last_accepted_at')->nullable();
            $table->timestampTz('last_consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_identity_id', 'provider_app_id_hash', 'provider_template_id'], 'wechat_grant_identity_app_template_unique');
            $table->index(['user_id', 'provider_template_id']);
        });

        Schema::create('wechat_subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_identity_id')->constrained('user_identities')->cascadeOnDelete();
            $table->char('provider_app_id_hash', 64);
            $table->string('request_id', 100);
            $table->char('result_hash', 64);
            $table->timestamps();
            $table->unique(['user_identity_id', 'provider_app_id_hash', 'request_id'], 'wechat_request_identity_app_request_unique');
        });

        $now = now();
        $external = ['task_revision_required', 'task_completed', 'task_claim_expired', 'task_appeal_approved', 'task_appeal_rejected', 'task_reward_delivered', 'task_reward_failed', 'order_pending_payment', 'order_paid', 'order_fulfilled', 'order_cancelled', 'order_partially_refunded', 'order_refunded', 'post_review_approved', 'post_review_rejected', 'post_warning', 'post_hidden', 'post_restored', 'post_appeal_rejected'];
        $scenes = [
            'task_claimed', 'task_progress_started', 'task_ready_to_submit', 'task_submission_received', 'task_review_started', 'task_submission_withdrawn',
            'task_revision_required', 'task_completed', 'task_claim_cancelled', 'task_claim_expired',
            'task_appeal_submitted', 'task_appeal_approved', 'task_appeal_rejected',
            'task_reward_delivered', 'task_reward_failed',
            'order_pending_payment', 'order_paid', 'order_fulfillment_updated', 'order_fulfilled',
            'order_completed', 'order_cancelled', 'order_partially_refunded', 'order_refunded',
            'post_review_pending', 'post_published', 'post_review_approved', 'post_review_rejected',
            'post_warning', 'post_hidden', 'post_deleted', 'post_appeal_submitted', 'post_restored', 'post_appeal_rejected',
        ];
        DB::table('notification_scene_configs')->insert(array_map(static fn (string $scene): array => [
            'scene_code' => $scene,
            'enabled' => true,
            'channels' => json_encode(in_array($scene, $external, true) ? ['in_app', 'wechat_mini'] : ['in_app']),
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scenes));
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_subscription_requests');
        Schema::dropIfExists('wechat_subscription_grants');
        Schema::dropIfExists('notification_business_events');
        Schema::dropIfExists('notification_scene_configs');
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_identity_id');
        });
    }
};
