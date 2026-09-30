<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icon_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('图标唯一标识');
            $table->string('name')->comment('图标名称');
            $table->string('category')->comment('分类：tabbar/module/order/community/service/common');
            $table->string('svg_path')->comment('SVG 文件路径');
            $table->string('fallback_char')->nullable()->comment('降级字符');
            $table->string('label')->nullable()->comment('显示文字');
            $table->string('description')->nullable()->comment('描述文字');
            $table->string('tone')->default('default')->comment('色调：yellow/cyan/green/blue/purple/red');
            $table->unsignedInteger('sort_order')->default(0)->comment('排序');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->json('meta')->nullable()->comment('扩展配置');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icon_configs');
    }
};
