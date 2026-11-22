<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_sessions', function (Blueprint $table) {
            $table->string('login_method', 16)->default('password')->after('remembered');
        });
    }

    public function down(): void
    {
        Schema::table('admin_sessions', fn (Blueprint $table) => $table->dropColumn('login_method'));
    }
};
