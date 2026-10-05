<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['follower_id', 'following_id']);
            $table->index(['following_id', 'created_at']);
        });

        Schema::create('community_post_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['community_post_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('community_posts', function (Blueprint $table): void {
            $table->unsignedInteger('favorite_count')->default(0)->after('like_count');
            $table->string('location_name', 80)->nullable()->after('category_code');
        });
    }

    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropColumn(['favorite_count', 'location_name']);
        });
        Schema::dropIfExists('community_post_favorites');
        Schema::dropIfExists('user_follows');
    }
};
