<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_printers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('vendor', 16);
            $table->string('sn', 64);
            $table->string('username', 64)->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->unsignedSmallInteger('paper_width')->default(58);
            $table->text('template')->nullable();
            $table->string('status', 16)->default('enabled');
            $table->timestampTz('last_test_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 300)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['campus_id', 'status']);
        });

        Schema::create('printer_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained('campus_printers')->nullOnDelete();
            $table->string('business_type', 32);
            $table->string('business_id', 26);
            $table->text('content');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('printed_at')->nullable();
            $table->timestamps();

            $table->index(['business_type', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_print_jobs');
        Schema::dropIfExists('campus_printers');
    }
};
