<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 40)->index();
            $table->string('original_name', 255);
            $table->string('extension', 16);
            $table->string('declared_mime', 100);
            $table->string('detected_mime', 100)->nullable();
            $table->unsignedBigInteger('declared_size');
            $table->unsignedBigInteger('actual_size')->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->string('disk', 32);
            $table->string('quarantine_path', 500)->unique();
            $table->string('status', 24)->default('issued')->index();
            $table->string('rejection_code', 64)->nullable();
            $table->string('upload_token_hash', 64)->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->timestampTz('ticket_expires_at');
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_user_id', 'idempotency_key_hash']);
            $table->index(['owner_user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};
