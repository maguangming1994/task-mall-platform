<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('second_hand_orders', function (Blueprint $table): void {
            $table->string('buyer_message', 100)->nullable()->after('address_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('second_hand_orders', function (Blueprint $table): void {
            $table->dropColumn('buyer_message');
        });
    }
};
