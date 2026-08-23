<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->string('category_code', 32)->default('experience')->after('content');
            $table->index(['status', 'category_code', 'published_at', 'id'], 'community_posts_category_feed_idx');
        });
        Schema::table('community_post_versions', function (Blueprint $table): void {
            $table->string('category_code', 32)->default('experience')->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('community_post_versions', function (Blueprint $table): void {
            $table->dropColumn('category_code');
        });
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropIndex('community_posts_category_feed_idx');
            $table->dropColumn('category_code');
        });
    }
};
