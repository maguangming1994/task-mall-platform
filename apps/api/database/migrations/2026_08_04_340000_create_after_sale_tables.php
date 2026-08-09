<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('after_sale_reasons', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 64)->unique();
            $table->string('name', 100);
            $table->json('service_types');
            $table->string('default_responsibility', 24);
            $table->string('return_shipping_payer', 24);
            $table->boolean('evidence_required')->default(false);
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('reason_name_snapshot', 100)->nullable();
            $table->string('responsibility_party', 24)->default('pending');
            $table->string('return_shipping_payer', 24)->default('pending');
            $table->string('return_method', 24)->nullable();
            $table->json('reason_rule_snapshot')->nullable();
            $table->json('evidence_file_ids')->nullable();
            $table->timestampTz('user_action_deadline_at')->nullable()->index();
            $table->boolean('original_shipping_refunded')->default(false);
        });

        Schema::create('return_shipments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('refund_id')->unique()->constrained()->restrictOnDelete();
            $table->string('method', 24);
            $table->string('carrier_code', 64)->nullable();
            $table->string('tracking_no', 100)->nullable();
            $table->foreignId('payment_proof_file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->unsignedBigInteger('paid_shipping_amount')->default(0);
            $table->unsignedBigInteger('compensation_amount')->default(0);
            $table->string('responsibility_party', 24);
            $table->string('status', 24)->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('submitted_at');
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();
            $table->unique(['carrier_code', 'tracking_no']);
        });

        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('exchange_no', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason_code', 64);
            $table->string('reason_name_snapshot', 100);
            $table->json('reason_rule_snapshot');
            $table->string('description', 500)->nullable();
            $table->json('evidence_file_ids')->nullable();
            $table->string('return_method', 24);
            $table->string('status', 24)->index();
            $table->string('responsibility_party', 24)->default('pending');
            $table->string('return_shipping_payer', 24)->default('pending');
            $table->string('replacement_shipping_payer', 24)->default('pending');
            $table->timestampTz('return_deadline_at')->nullable()->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['order_item_id', 'status', 'created_at']);
        });

        Schema::create('exchange_shipments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('exchange_id')->constrained()->restrictOnDelete();
            $table->string('direction', 24);
            $table->string('method', 24);
            $table->string('carrier_code', 64)->nullable();
            $table->string('tracking_no', 100)->nullable();
            $table->string('no_logistics_note', 500)->nullable();
            $table->foreignId('payment_proof_file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->unsignedBigInteger('paid_shipping_amount')->default(0);
            $table->unsignedBigInteger('compensation_amount')->default(0);
            $table->string('status', 24)->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('shipped_at');
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_shipments');
        Schema::dropIfExists('exchanges');
        Schema::dropIfExists('return_shipments');
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn([
            'reason_name_snapshot', 'responsibility_party', 'return_shipping_payer', 'return_method',
            'reason_rule_snapshot', 'evidence_file_ids', 'user_action_deadline_at', 'original_shipping_refunded',
        ]));
        Schema::dropIfExists('after_sale_reasons');
    }
};
