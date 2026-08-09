<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uat_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('release_version', 80);
            $table->string('environment', 40);
            $table->string('status', 32)->index();
            $table->string('automated_suite_status', 24);
            $table->boolean('external_channels_executed')->default(false);
            $table->boolean('real_device_executed')->default(false);
            $table->json('summary');
            $table->char('report_hash', 64)->unique();
            $table->timestampTz('completed_at');
            $table->timestamps();
        });

        Schema::create('uat_case_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uat_run_id')->constrained()->restrictOnDelete();
            $table->string('case_code', 64);
            $table->string('scope', 32)->index();
            $table->string('status', 24)->index();
            $table->json('evidence');
            $table->timestamps();
            $table->unique(['uat_run_id', 'case_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_case_results');
        Schema::dropIfExists('uat_runs');
    }
};
