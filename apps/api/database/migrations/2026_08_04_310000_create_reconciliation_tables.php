<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('provider', 24);
            $table->date('business_date')->index();
            $table->string('source', 24);
            $table->string('status', 24)->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->unsignedInteger('provider_count')->default(0);
            $table->unsignedBigInteger('provider_amount')->default(0);
            $table->unsignedInteger('local_count')->default(0);
            $table->unsignedBigInteger('local_amount')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('difference_count')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reconciliation_entries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reconciliation_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_no', 40);
            $table->string('provider_trade_no', 128)->nullable();
            $table->unsignedBigInteger('provider_amount')->nullable();
            $table->unsignedBigInteger('local_amount')->nullable();
            $table->string('provider_state', 32)->nullable();
            $table->string('local_state', 32)->nullable();
            $table->string('result', 32)->index();
            $table->string('resolution_status', 24)->default('open')->index();
            $table->char('source_row_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['reconciliation_run_id', 'payment_no']);
        });

        Schema::create('reconciliation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_actions');
        Schema::dropIfExists('reconciliation_entries');
        Schema::dropIfExists('reconciliation_runs');
    }
};
