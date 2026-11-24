<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->text('message')->nullable()->change();
            $table->string('msg_type', 16)->default('text')->after('message');
            $table->json('payload')->nullable()->after('msg_type');
            $table->timestampTz('scheduled_at')->nullable()->after('last_synced_at');
            $table->timestampTz('dispatched_at')->nullable()->after('scheduled_at');
            $table->timestampTz('canceled_at')->nullable()->after('dispatched_at');
            $table->index(['status', 'scheduled_at'], 'robot_message_status_scheduled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->dropIndex('robot_message_status_scheduled_idx');
            $table->dropColumn(['msg_type', 'payload', 'scheduled_at', 'dispatched_at', 'canceled_at']);
            $table->text('message')->nullable(false)->change();
        });
    }
};
