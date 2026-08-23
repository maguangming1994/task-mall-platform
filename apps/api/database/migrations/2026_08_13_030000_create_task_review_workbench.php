<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->foreignId('reviewer_admin_id')->nullable()->after('status')->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('review_assigned_at')->nullable()->after('reviewer_admin_id');
            $table->timestampTz('review_lock_expires_at')->nullable()->after('review_assigned_at')->index();
            $table->json('revision_step_ids')->nullable()->after('file_public_ids');
            $table->foreignId('supersedes_submission_id')->nullable()->after('revision_step_ids')->constrained('task_submissions')->nullOnDelete();
            $table->index(['reviewer_admin_id', 'status']);
        });

        Schema::create('task_step_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_submission_id')->constrained()->restrictOnDelete();
            $table->string('step_id', 80);
            $table->string('step_title', 160);
            $table->string('decision', 16);
            $table->string('reason_code', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('evidence_file_public_ids')->nullable();
            $table->timestamps();
            $table->unique(['task_review_id', 'step_id']);
            $table->index(['task_submission_id', 'decision']);
        });

        Schema::create('task_review_reasons', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 64)->unique();
            $table->string('label', 100);
            $table->string('suggested_note', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active')->index();
            $table->boolean('system_defined')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('task_review_reasons')->insert([
            ['public_id' => (string) Str::ulid(), 'code' => 'evidence_unclear', 'label' => '证明材料不清晰', 'suggested_note' => '关键内容无法辨认，请重新上传清晰、完整且未遮挡的材料。', 'sort_order' => 10, 'status' => 'active', 'system_defined' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'requirements_not_met', 'label' => '未满足步骤要求', 'suggested_note' => '提交结果未达到该步骤的验收要求，请按步骤说明补充。', 'sort_order' => 20, 'status' => 'active', 'system_defined' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'content_mismatch', 'label' => '材料与任务不符', 'suggested_note' => '提交内容与本任务或该步骤不匹配，请核对后重新提交。', 'sort_order' => 30, 'status' => 'active', 'system_defined' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'duplicate_or_fraud', 'label' => '疑似重复或异常材料', 'suggested_note' => '材料与既有提交高度重复或存在异常，请提供本次真实完成凭证。', 'sort_order' => 40, 'status' => 'active', 'system_defined' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'other', 'label' => '其他原因', 'suggested_note' => '请根据审核情况填写具体、可执行的补交说明。', 'sort_order' => 99, 'status' => 'active', 'system_defined' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_review_reasons');
        Schema::dropIfExists('task_step_reviews');

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropIndex(['reviewer_admin_id', 'status']);
            $table->dropForeign(['supersedes_submission_id']);
            $table->dropForeign(['reviewer_admin_id']);
            $table->dropIndex(['review_lock_expires_at']);
            $table->dropColumn(['reviewer_admin_id', 'review_assigned_at', 'review_lock_expires_at', 'revision_step_ids', 'supersedes_submission_id']);
        });
    }
};
