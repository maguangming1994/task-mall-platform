<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bank_cards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cardholder_name', 40);
            $table->string('bank_name', 40);
            $table->string('card_tail', 4);
            $table->string('phone_masked', 20);
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bank_cards');
    }
};
