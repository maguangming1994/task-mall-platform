<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_forward_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->json('filter_rules');
            $table->json('template_config');
            $table->json('robot_targets');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('robot_assistants', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('name', 191);
            $table->text('remark')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->string('app_id', 80)->unique();
            $table->text('app_secret');
            $table->json('robot_bindings');
            $table->timestampTz('webhook_last_tested_at')->nullable();
            $table->string('webhook_last_test_status', 32)->nullable();
            $table->string('webhook_last_test_message', 500)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->foreignId('forward_config_id')->nullable()->after('created_by_admin_id')
                ->constrained('robot_forward_configs')->nullOnDelete();
            $table->string('trigger_type', 32)->default('MANUAL')->after('forward_config_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->dropForeign(['forward_config_id']);
            $table->dropIndex(['trigger_type']);
            $table->dropColumn(['forward_config_id', 'trigger_type']);
        });
        Schema::dropIfExists('robot_assistants');
        Schema::dropIfExists('robot_forward_configs');
    }
};
