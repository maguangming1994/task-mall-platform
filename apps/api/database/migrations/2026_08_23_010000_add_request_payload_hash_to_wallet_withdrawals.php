<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table): void {
            // Nullable keeps this migration compatible with withdrawals created before payload binding was introduced.
            $table->char('request_payload_hash', 64)->nullable()->after('idempotency_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table): void {
            $table->dropColumn('request_payload_hash');
        });
    }
};
