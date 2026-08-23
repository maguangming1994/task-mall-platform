<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_claims', function (Blueprint $table) {
            $table->timestampTz('cycle_starts_at')->nullable()->after('cycle_key');
            $table->timestampTz('cycle_ends_at')->nullable()->after('cycle_starts_at')->index();
            $table->unsignedBigInteger('progress_current')->default(0)->after('progress_started_at');
            $table->unsignedBigInteger('progress_target')->default(1)->after('progress_current');
            $table->timestampTz('last_progress_at')->nullable()->after('progress_target');
        });

        Schema::table('task_verification_events', function (Blueprint $table) {
            $table->string('cycle_key', 64)->nullable()->after('user_id')->index();
            $table->unsignedBigInteger('progress_delta')->default(0)->after('goal_met');
            $table->unsignedBigInteger('progress_after')->default(0)->after('progress_delta');
        });
    }

    public function down(): void
    {
        Schema::table('task_verification_events', function (Blueprint $table) {
            $table->dropIndex(['cycle_key']);
            $table->dropColumn(['cycle_key', 'progress_delta', 'progress_after']);
        });
        Schema::table('task_claims', function (Blueprint $table) {
            $table->dropIndex(['cycle_ends_at']);
            $table->dropColumn(['cycle_starts_at', 'cycle_ends_at', 'progress_current', 'progress_target', 'last_progress_at']);
        });
    }
};
