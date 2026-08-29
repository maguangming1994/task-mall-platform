<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('province', 60)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('district', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('admin_user_campus', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['admin_user_id', 'campus_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('campus_id')->nullable()->after('public_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campus_id');
        });
        Schema::dropIfExists('admin_user_campus');
        Schema::dropIfExists('campuses');
    }
};
