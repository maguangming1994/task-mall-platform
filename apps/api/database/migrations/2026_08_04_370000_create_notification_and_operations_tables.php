<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('wechat_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('marketing_subscribed')->default(false);
            $table->time('quiet_hours_start')->default('22:00');
            $table->time('quiet_hours_end')->default('08:00');
            $table->timestamps();
        });
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('scene_code', 64);
            $table->string('channel', 24);
            $table->string('locale', 16)->default('zh-CN');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('current_version_no')->default(0);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['scene_code', 'channel', 'locale']);
        });
        Schema::create('notification_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('subject', 160);
            $table->text('body');
            $table->json('allowed_variables');
            $table->string('provider_template_id', 160)->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_template_id', 'version_no']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24)->index();
            $table->string('scene_code', 64);
            $table->string('title', 160);
            $table->text('body');
            $table->json('data')->nullable();
            $table->boolean('essential')->default(false);
            $table->char('event_key_hash', 64)->unique();
            $table->timestampTz('read_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24)->index();
            $table->json('payload_snapshot');
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->string('provider_message_id', 160)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel']);
        });
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('title', 160);
            $table->text('body');
            $table->json('channels');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('scheduled_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_template_versions');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_preferences');
    }
};
