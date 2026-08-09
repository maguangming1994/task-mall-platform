<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_sessions', function (Blueprint $table) {
            $table->timestampTz('verified_at')->nullable()->after('last_active_at');
        });
        DB::table('admin_sessions')->whereNull('verified_at')->update(['verified_at' => DB::raw('issued_at')]);

        Schema::create('admin_mfa_factors', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('admin_user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('totp');
            $table->text('secret_ciphertext');
            $table->string('status', 16)->default('pending')->index();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->char('challenge_token_hash', 64)->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestampTz('used_at')->nullable();
            $table->timestamps();
            $table->unique(['admin_user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_mfa_recovery_codes');
        Schema::dropIfExists('admin_mfa_challenges');
        Schema::dropIfExists('admin_mfa_factors');
        Schema::table('admin_sessions', fn (Blueprint $table) => $table->dropColumn('verified_at'));
    }
};
