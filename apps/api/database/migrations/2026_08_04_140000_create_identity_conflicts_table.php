<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merged_into_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('user_identities', function (Blueprint $table) {
            $table->text('provider_user_value')->nullable();
            $table->text('union_value')->nullable();
        });

        Schema::create('identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('kind', 40)->index();
            $table->string('provider', 32);
            $table->char('provider_user_id_hash', 64);
            $table->text('provider_user_value')->nullable();
            $table->char('union_id_hash', 64)->nullable()->index();
            $table->text('union_value')->nullable();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('candidate_user_ids')->default('[]');
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('resolution', 40)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_user_id_hash', 'status'], 'identity_conflict_subject_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_conflicts');

        Schema::table('user_identities', function (Blueprint $table) {
            $table->dropColumn(['provider_user_value', 'union_value']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_user_id');
        });
    }
};
