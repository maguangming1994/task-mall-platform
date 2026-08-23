<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            // Nullable keeps refunds written before this release replayable.
            $table->char('request_fingerprint_hash', 64)->nullable()->after('idempotency_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint_hash');
        });
    }
};
