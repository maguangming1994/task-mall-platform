<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_membership_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('from_campus_id')->nullable();
            $table->unsignedBigInteger('to_campus_id');
            $table->string('reason')->nullable();                    // 用户自助/管理员强改/兜底回填
            $table->unsignedBigInteger('changed_by_admin_id')->nullable(); // 管理员操作时为非空
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_membership_changes');
    }
};
