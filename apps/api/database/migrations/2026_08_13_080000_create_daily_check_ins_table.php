<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_check_ins', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('check_in_date');
            $table->unsignedInteger('streak_days')->default(1);
            $table->unsignedInteger('reward_points')->default(0);
            $table->string('reward_event_key', 160)->unique();
            $table->timestampsTz();

            $table->unique(['user_id', 'check_in_date']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_check_ins');
    }
};
