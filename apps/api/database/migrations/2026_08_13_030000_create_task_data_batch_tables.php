<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_data_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('original_filename', 255);
            $table->string('source_format', 16)->default('csv');
            $table->json('source_headers');
            $table->json('field_mapping');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->string('status', 24)->default('ready')->index();
            $table->char('snapshot_hash', 64);
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('task_version_id')->nullable()->constrained()->restrictOnDelete()->unique();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_data_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_data_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->json('source_payload');
            $table->json('mapped_payload');
            $table->json('validation_errors')->nullable();
            $table->string('status', 24)->default('available')->index();
            $table->foreignId('allocated_task_claim_id')->nullable()->constrained('task_claims')->restrictOnDelete()->unique();
            $table->foreignId('allocated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('allocated_at')->nullable();
            $table->unsignedInteger('release_count')->default(0);
            $table->timestampTz('last_released_at')->nullable();
            $table->timestamps();
            $table->unique(['task_data_batch_id', 'row_no']);
            $table->index(['task_data_batch_id', 'status', 'row_no']);
        });

        Schema::create('task_data_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_data_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('allocated')->index();
            $table->timestampTz('allocated_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['task_data_item_id', 'allocated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_data_allocations');
        Schema::dropIfExists('task_data_items');
        Schema::dropIfExists('task_data_batches');
    }
};
