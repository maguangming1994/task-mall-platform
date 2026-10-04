<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table): void {
            // 银行卡人工打款通道：提交提现时冻结收款卡快照，避免后续删卡/改卡导致无收款信息
            $table->json('bank_card_snapshot')->nullable()->after('channel_reference');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table): void {
            $table->dropColumn('bank_card_snapshot');
        });
    }
};
