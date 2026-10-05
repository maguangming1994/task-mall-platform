<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 校区级模块覆盖：无记录 = inherit（跟随全局），保证升级无感。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('status', 24)->default('inherit');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            // 复合唯一索引的最左前缀同时覆盖 campus_id 查询，无需再建单列索引
            $table->unique(['campus_id', 'code'], 'campus_feature_flags_campus_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_feature_flags');
    }
};
