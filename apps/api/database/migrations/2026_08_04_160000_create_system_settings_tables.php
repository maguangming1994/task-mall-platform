<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_setting_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 120)->unique();
            $table->string('group_code', 48)->index();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('value_type', 24);
            $table->string('unit', 24)->nullable();
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('default_value');
            $table->string('risk_level', 16)->default('normal');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('system_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_definition_id')->constrained('system_setting_definitions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('value');
            $table->string('source', 24)->default('publish');
            $table->foreignId('source_version_id')->nullable()->constrained('system_setting_versions')->nullOnDelete();
            $table->string('reason', 500);
            $table->foreignId('published_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('published_at');
            $table->timestamps();

            $table->unique(['setting_definition_id', 'version']);
            $table->index(['setting_definition_id', 'published_at']);
        });

        Schema::create('system_setting_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_definition_id')->unique()->constrained('system_setting_definitions')->cascadeOnDelete();
            $table->json('value');
            $table->foreignId('base_version_id')->nullable()->constrained('system_setting_versions')->nullOnDelete();
            $table->foreignId('rollback_source_version_id')->nullable()->constrained('system_setting_versions')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('review_status', 24)->default('not_required');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('review_comment', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_setting_drafts');
        Schema::dropIfExists('system_setting_versions');
        Schema::dropIfExists('system_setting_definitions');
    }
};
