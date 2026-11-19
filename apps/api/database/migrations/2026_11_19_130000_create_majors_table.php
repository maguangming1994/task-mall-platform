<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('majors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('专业代码');
            $table->string('name', 64)->comment('专业名称');
            $table->string('category', 32)->comment('学科门类：哲学/经济学/法学/教育学/文学/历史学/理学/工学/农学/医学/管理学/艺术学');
            $table->unsignedInteger('category_sort')->default(0)->comment('门类排序');
            $table->unsignedInteger('sort_order')->default(0)->comment('专业排序');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->index(['category', 'sort_order']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('majors');
    }
};
