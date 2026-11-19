<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poster_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // 模板定位：一个业务场景对应一个 code，如 community_post / material_share
            $table->string('code', 64)->index();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // 画布尺寸
            $table->unsignedInteger('canvas_width')->default(330);
            $table->unsignedInteger('canvas_height')->default(520);

            // 背景：gradient / image / solid
            $table->string('background_type', 16)->default('gradient');
            // JSON 载荷：
            //   gradient -> {"stops":["#0e799d","#19c6d1","#76df63"],"angle":135}
            //   image    -> {"image_url":"https://...","mode":"cover"}
            //   solid    -> {"color":"#ffffff"}
            $table->json('background_value')->nullable();

            // Banner 顶部区域（文案/颜色）
            $table->json('banner_config')->nullable();
            // 示例: {"text":"校园生活圈","subtitle":"一站式搞定校园生活","text_color":"#ffffff","bg_tone":"#ffdb4d"}

            // 主体卡片（含话题标签位置、最大行数等）
            $table->json('body_config')->nullable();

            // 底部二维码区域
            $table->json('qrcode_config')->nullable();
            // 示例: {"cta_text":"长按扫码，参与评论","brand_watermark":"任务商城 · 校园社区"}

            // 日期匹配规则：NULL = 全局兜底（默认模板）
            // JSON: {"mode":"range","ranges":[{"start":"2026-02-10","end":"2026-02-20"},...]}
            //       {"mode":"monthday","monthdays":["02-14","12-24"]}
            //       {"mode":"weekday","weekdays":[1,2,3,4,5]}
            // 匹配时按 priority 降序取第一个命中；无命中则取 code 下 is_default=true 的模板
            $table->json('date_rule')->nullable();

            // 优先级：越大越先尝试匹配
            $table->unsignedInteger('priority')->default(0);
            // 是否为该 code 的默认兜底模板（无日期规则匹配时使用）
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['code', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poster_templates');
    }
};
