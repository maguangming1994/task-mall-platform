<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('document_type', 48)->index();
            $table->string('version', 40);
            $table->string('title', 160);
            $table->string('content_format', 16)->default('markdown');
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->boolean('consent_required')->default(true);
            $table->boolean('withdrawal_allowed')->default(true);
            $table->string('status', 24)->default('draft')->index();
            $table->timestampTz('effective_at')->nullable()->index();
            $table->timestampTz('published_at')->nullable();
            $table->string('change_summary', 500)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_type', 'version']);
            $table->index(['document_type', 'status', 'effective_at']);
        });

        Schema::create('user_legal_consents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_document_version_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->string('client_platform', 32);
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'idempotency_key_hash']);
            $table->index(['user_id', 'legal_document_version_id', 'created_at'], 'user_legal_consent_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legal_consents');
        Schema::dropIfExists('legal_document_versions');
    }
};
