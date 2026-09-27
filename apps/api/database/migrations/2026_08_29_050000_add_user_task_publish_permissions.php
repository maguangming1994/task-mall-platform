<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->boolean('is_service_account')->default(false)->index()->after('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_publish_tasks')->default(false)->index()->after('profile_completed_at');
            $table->timestampTz('task_publish_permission_granted_at')->nullable()->after('can_publish_tasks');
            $table->foreignId('task_publish_permission_granted_by_admin_id')
                ->nullable()
                ->after('task_publish_permission_granted_at')
                ->constrained('admin_users')
                ->nullOnDelete();
        });

        Schema::table('task_publishers', function (Blueprint $table): void {
            $table->unique('linked_user_id', 'task_publishers_linked_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_publishers', function (Blueprint $table): void {
            $table->dropUnique('task_publishers_linked_user_id_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('task_publish_permission_granted_by_admin_id');
            $table->dropColumn(['can_publish_tasks', 'task_publish_permission_granted_at']);
        });

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn('is_service_account');
        });
    }
};
