<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('task_submissions', 'review_reminded_at')) {
            Schema::table('task_submissions', fn (Blueprint $table) => $table->timestampTz('review_reminded_at')->nullable()->after('review_due_at'));
        }
        Schema::create('task_reward_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_reward_delivery_id')->constrained()->restrictOnDelete();
            $table->string('source', 24);
            $table->char('idempotency_key_hash', 64);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['task_reward_delivery_id', 'idempotency_key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reward_delivery_attempts');
        if (Schema::hasColumn('task_submissions', 'review_reminded_at')) {
            Schema::table('task_submissions', fn (Blueprint $table) => $table->dropColumn('review_reminded_at'));
        }
    }
};
