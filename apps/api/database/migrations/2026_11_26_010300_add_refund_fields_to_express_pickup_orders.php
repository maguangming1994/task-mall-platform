<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('express_pickup_orders', function (Blueprint $table) {
            $table->string('refund_no', 40)->nullable()->unique()->after('refunded_at');
            $table->string('refund_status', 16)->nullable()->index()->after('refund_no');
        });
    }

    public function down(): void
    {
        Schema::table('express_pickup_orders', function (Blueprint $table) {
            $table->dropUnique(['refund_no']);
            $table->dropIndex(['refund_status']);
            $table->dropColumn(['refund_no', 'refund_status']);
        });
    }
};
