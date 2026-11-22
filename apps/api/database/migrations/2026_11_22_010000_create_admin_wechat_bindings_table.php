<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_wechat_bindings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('admin_user_id')->unique()->constrained()->cascadeOnDelete();
            // 只保存 openid 的 HMAC 摘要，避免数据库泄露后可直接冒用微信身份
            $table->char('openid_hash', 64);
            $table->char('app_id_hash', 64);
            $table->timestampTz('bound_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['openid_hash', 'app_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_wechat_bindings');
    }
};
