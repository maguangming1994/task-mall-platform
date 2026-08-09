<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('nickname', 80);
            $table->unsignedBigInteger('avatar_file_id')->nullable();
            $table->text('phone')->nullable();
            $table->char('phone_hash', 64)->nullable()->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->timestampTz('profile_completed_at')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
