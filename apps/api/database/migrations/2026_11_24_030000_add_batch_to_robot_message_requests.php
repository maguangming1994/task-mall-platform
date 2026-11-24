<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->string('batch_id', 64)->nullable()->after('idempotency_key_hash');
            $table->unsignedSmallInteger('batch_sequence')->nullable()->after('batch_id');
            $table->unique(['batch_id', 'batch_sequence'], 'robot_message_batch_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('robot_message_requests', function (Blueprint $table): void {
            $table->dropUnique('robot_message_batch_sequence_unique');
            $table->dropColumn(['batch_id', 'batch_sequence']);
        });
    }
};
