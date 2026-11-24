<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_forward_configs', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_forwarded_post_id')->nullable()->after('robot_targets');
            $table->timestamp('last_forwarded_at')->nullable()->after('last_forwarded_post_id');
            $table->timestamp('last_dispatch_at')->nullable()->after('last_forwarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('robot_forward_configs', function (Blueprint $table): void {
            $table->dropColumn(['last_forwarded_post_id', 'last_forwarded_at', 'last_dispatch_at']);
        });
    }
};
