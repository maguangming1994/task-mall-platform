<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            // 短链码，6 位字母数字，唯一
            $table->string('code', 6)->unique();
            // 目标类型：community_post / user 等，预留多场景
            $table->string('target_type', 40);
            // 目标主键 ID（社区帖子用自增 ID，避免 public_id 长度泄露到短链）
            $table->unsignedBigInteger('target_id');
            // 访问计数
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
