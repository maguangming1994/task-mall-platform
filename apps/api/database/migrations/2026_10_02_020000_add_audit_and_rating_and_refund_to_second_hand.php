<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 闲置物品审核状态
        Schema::table('second_hand_items', function (Blueprint $table) {
            $table->string('audit_status', 24)->default('approved')->after('status');
            $table->text('rejected_reason')->nullable()->after('audit_status');
            $table->index('audit_status');
            $table->index(['campus_id', 'audit_status']);
        });

        // 闲置评价
        Schema::create('second_hand_ratings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('second_hand_orders')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('second_hand_items')->restrictOnDelete();
            $table->foreignId('rater_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ratee_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('content')->nullable();
            $table->json('images')->nullable();
            $table->text('reply_content')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'rater_user_id']);
            $table->index(['ratee_user_id', 'created_at']);
            $table->index(['item_id', 'created_at']);
        });

        // 闲置退款
        Schema::create('second_hand_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('second_hand_orders')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reason', 120);
            $table->json('images')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('seller_note')->nullable();
            $table->timestampTz('seller_responded_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('arbitrated_at')->nullable();
            $table->timestampTz('auto_approve_at')->nullable();
            $table->timestamps();
            $table->unique('order_id');
            $table->index(['seller_user_id', 'status']);
            $table->index(['buyer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('second_hand_refunds');
        Schema::dropIfExists('second_hand_ratings');
        Schema::table('second_hand_items', function (Blueprint $table) {
            $table->dropIndex(['campus_id', 'audit_status']);
            $table->dropColumn(['audit_status', 'rejected_reason']);
        });
    }
};
