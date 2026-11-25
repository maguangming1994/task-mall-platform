<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->string('visibility', 20)->default('public')->after('location_name');
            $table->decimal('location_latitude', 10, 7)->nullable()->after('visibility');
            $table->decimal('location_longitude', 11, 7)->nullable()->after('location_latitude');
            $table->index(['status', 'visibility', 'published_at', 'id'], 'community_posts_visibility_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropIndex('community_posts_visibility_feed_idx');
            $table->dropColumn(['visibility', 'location_latitude', 'location_longitude']);
        });
    }
};
