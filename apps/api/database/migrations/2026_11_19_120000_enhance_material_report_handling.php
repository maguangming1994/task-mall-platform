<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_reports', function (Blueprint $table) {
            // 处理结论：upheld 举报成立 / dismissed 不成立 / other 其他处理
            $table->string('decision', 24)->nullable()->after('status');
            $table->text('handling_note')->nullable()->after('detail');
            $table->index(['material_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('material_reports', function (Blueprint $table) {
            $table->dropIndex(['material_id', 'status']);
            $table->dropColumn(['decision', 'handling_note']);
        });
    }
};
