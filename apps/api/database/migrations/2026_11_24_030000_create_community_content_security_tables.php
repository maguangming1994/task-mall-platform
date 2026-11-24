<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_comments', function (Blueprint $table) {
            $table->timestampTz('reviewed_at')->nullable()->after('review_note');
        });

        Schema::create('community_content_security_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique()->default('default');
            $table->string('provider', 20)->default('wechat');
            $table->boolean('enabled')->default(true);
            $table->string('region', 64)->default('cn-shanghai');
            $table->string('endpoint')->default('green-cip.cn-shanghai.aliyuncs.com');
            $table->string('service', 128)->default('ugc_moderation_byllm');
            $table->text('access_key_id')->nullable();
            $table->text('access_key_secret')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('community_content_security_logs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('content_type', 20);
            $table->unsignedBigInteger('content_id')->nullable()->index();
            $table->char('content_public_id', 26)->nullable()->index();
            $table->unsignedInteger('content_version_no')->nullable();
            $table->string('content_field', 20);
            $table->string('subject_snapshot', 120)->nullable();
            $table->text('content_snapshot');
            $table->char('content_hash', 64);
            $table->string('provider', 20);
            $table->string('verdict', 20)->index();
            $table->string('risk_level', 20)->nullable()->index();
            $table->json('risk_labels')->nullable();
            $table->string('skip_reason', 100)->nullable();
            $table->string('request_id', 191)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'content_type', 'created_at'], 'community_security_campus_type_created');
            $table->index(['provider', 'created_at'], 'community_security_provider_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_content_security_logs');
        Schema::dropIfExists('community_content_security_settings');
        Schema::table('community_comments', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
