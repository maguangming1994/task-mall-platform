<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_batches', function (Blueprint $table): void {
            // Nullable keeps batches created before request binding replayable.
            $table->char('request_fingerprint_hash', 64)->nullable()->after('idempotency_key_hash');
        });

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            // Nullable keeps recharge records created before request binding replayable.
            $table->char('request_fingerprint_hash', 64)->nullable()->after('idempotency_key_hash');
        });

        Schema::table('exchanges', function (Blueprint $table): void {
            // Nullable keeps exchange records created before request binding replayable.
            $table->char('request_fingerprint_hash', 64)->nullable()->after('idempotency_key_hash');
        });

        Schema::table('exchange_shipments', function (Blueprint $table): void {
            // Return/replacement shipments also use an idempotency key and need
            // a payload binding when the service is retried.
            $table->char('request_fingerprint_hash', 64)->nullable()->after('idempotency_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_shipments', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint_hash');
        });

        Schema::table('exchanges', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint_hash');
        });

        Schema::table('wallet_recharges', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint_hash');
        });

        Schema::table('order_batches', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint_hash');
        });
    }
};
