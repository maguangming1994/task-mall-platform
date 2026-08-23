<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->timestamp('replacement_auto_complete_at')->nullable()->after('return_deadline_at')->index();
        });

        Schema::create('return_shipping_compensations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('requested_amount');
            $table->unsignedBigInteger('approved_amount')->default(0);
            $table->string('status', 24)->default('submitted')->index();
            $table->string('carrier_code', 64);
            $table->string('tracking_no', 100);
            $table->foreignId('payment_proof_file_id')->constrained('uploaded_files')->restrictOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->char('review_idempotency_key_hash', 64)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_shipping_compensations');
        Schema::table('exchanges', fn (Blueprint $table) => $table->dropColumn('replacement_auto_complete_at'));
    }
};
