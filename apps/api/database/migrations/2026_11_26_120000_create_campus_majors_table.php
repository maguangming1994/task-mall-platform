<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_majors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->comment('校区 ID');
            $table->string('major_name', 120)->comment('专业名称（可能包含学校自设方向）');
            $table->string('major_code', 20)->nullable()->comment('教育部专业代码');
            $table->string('category', 32)->nullable()->comment('学科门类');
            $table->string('level', 32)->nullable()->comment('培养层次：本科(普通)/专科(高职)');
            $table->unsignedInteger('sort_order')->default(0)->comment('展示排序');
            $table->timestamps();

            $table->unique(['campus_id', 'major_name', 'level'], 'campus_majors_unique');
            $table->index(['campus_id', 'category']);
            $table->index('major_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_majors');
    }
};
