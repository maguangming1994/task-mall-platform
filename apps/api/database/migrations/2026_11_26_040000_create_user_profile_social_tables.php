<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('bio', 160)->nullable()->after('nickname');
        });

        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['blocker_user_id', 'blocked_user_id']);
            $table->index(['blocked_user_id', 'blocker_user_id']);
        });

        Schema::create('user_profile_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('visitor_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('visit_count')->default(1);
            $table->timestamp('last_visited_at')->nullable();
            $table->timestamps();
            $table->unique(['profile_user_id', 'visitor_user_id']);
            $table->index(['profile_user_id', 'last_visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_visits');
        Schema::dropIfExists('user_blocks');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
