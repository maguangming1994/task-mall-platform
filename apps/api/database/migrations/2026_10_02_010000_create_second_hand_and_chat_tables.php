<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 闲置分类
        Schema::create('second_hand_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 40);
            $table->string('icon', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
        });

        // 闲置物品
        Schema::create('second_hand_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('second_hand_categories')->nullOnDelete();
            $table->string('title', 80);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price'); // 单位：分
            $table->string('condition', 24)->default('like_new')->comment('new/like_new/very_good/good/fair');
            $table->string('cover_file_public_id', 26)->nullable();
            $table->json('images')->nullable();
            $table->string('trade_type', 24)->default('meetup')->comment('meetup/mail/both');
            $table->string('location', 120)->nullable();
            $table->string('status', 24)->default('on_sale')->index()->comment('on_sale/reserved/sold/off_shelf');
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('favorite_count')->default(0);
            $table->char('idempotency_key_hash', 64);
            $table->timestamps();
            $table->unique(['seller_user_id', 'idempotency_key_hash']);
            $table->index(['campus_id', 'status']);
            $table->index(['seller_user_id', 'status']);
        });

        // 闲置订单
        Schema::create('second_hand_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('order_no', 40)->unique();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('second_hand_items')->restrictOnDelete();
            $table->string('title_snapshot', 80);
            $table->string('cover_snapshot', 26)->nullable();
            $table->unsignedBigInteger('price');
            $table->string('trade_type', 24)->default('meetup');
            $table->json('address_snapshot')->nullable();
            $table->string('status', 32)->default('pending_payment')->index();
            $table->string('cash_payment_method', 24)->default('wallet');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['buyer_user_id', 'status']);
            $table->index(['seller_user_id', 'status']);
        });

        // 聊天会话
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_a_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('second_hand_items')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('second_hand_orders')->nullOnDelete();
            $table->text('last_message')->nullable();
            $table->string('last_message_type', 24)->default('text');
            $table->foreignId('last_message_id')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->unsignedInteger('unread_a')->default(0);
            $table->unsignedInteger('unread_b')->default(0);
            $table->timestamps();
            $table->unique(['user_a_id', 'user_b_id', 'item_id']);
            $table->index(['user_a_id', 'last_message_at']);
            $table->index(['user_b_id', 'last_message_at']);
        });

        // 聊天消息
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 24)->default('text')->comment('text/image/system');
            $table->text('content');
            $table->json('meta')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        // 延迟添加会话最后消息外键（chat_messages 表创建后）
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreign('last_message_id')->references('id')->on('chat_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('second_hand_orders');
        Schema::dropIfExists('second_hand_items');
        Schema::dropIfExists('second_hand_categories');
    }
};
