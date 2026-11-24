<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_post_forward_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('forward_config_id')->nullable();
            $table->string('group_id', 100);
            $table->string('group_name', 100)->nullable();
            // success：已提交并拿到 XP 回执；failed：明确失败；unknown：无法确认是否投递
            $table->string('status', 16);
            $table->unsignedBigInteger('message_request_id')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('manual')->default(false);
            $table->timestamp('forwarded_at');
            $table->timestamps();
            $table->index(['post_id', 'forwarded_at']);
            $table->index(['forward_config_id', 'forwarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_post_forward_logs');
    }
};
