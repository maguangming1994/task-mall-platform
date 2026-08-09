<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->string('mode', 24)->default('disabled')->index();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->json('audience')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('feature_flag_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_flag_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('mode', 24);
            $table->unsignedTinyInteger('rollout_percentage');
            $table->json('audience')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['feature_flag_id', 'version']);
        });

        Schema::create('deployment_releases', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('release_version', 80)->unique();
            $table->string('environment', 40)->index();
            $table->char('commit_sha', 40);
            $table->char('artifact_hash', 64);
            $table->char('configuration_hash', 64);
            $table->string('status', 24)->default('prepared')->index();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('deployed_at')->nullable();
            $table->timestampTz('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deployment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_release_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 32)->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->unsignedTinyInteger('rollout_percentage');
            $table->string('reason', 500);
            $table->char('evidence_hash', 64);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_events');
        Schema::dropIfExists('deployment_releases');
        Schema::dropIfExists('feature_flag_versions');
        Schema::dropIfExists('feature_flags');
    }
};
