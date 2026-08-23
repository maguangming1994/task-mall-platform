<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('category', 64)->nullable()->index();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('copied_from_task_template_id')->nullable()->constrained('task_templates')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('updated_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });

        Schema::create('task_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->unsignedInteger('source_version_no')->nullable();
            $table->json('changed_fields');
            $table->json('content');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['task_template_id', 'version_no']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('source_task_template_id')->nullable()->after('creation_channel')->constrained('task_templates')->nullOnDelete();
            $table->unsignedInteger('source_task_template_version_no')->nullable()->after('source_task_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_task_template_id');
            $table->dropColumn('source_task_template_version_no');
        });
        Schema::dropIfExists('task_template_versions');
        Schema::dropIfExists('task_templates');
    }
};
