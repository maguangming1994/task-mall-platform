<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('category', 32);
                $table->string('subject', 160);
                $table->string('status', 24)->default('open');
                $table->string('priority', 16)->default('normal');
                $table->string('related_type', 32)->nullable();
                $table->string('related_public_id', 26)->nullable();
                $table->timestampTz('last_replied_at')->nullable();
                $table->timestampTz('closed_at')->nullable();
                $table->timestampsTz();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'updated_at']);
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
                $table->string('sender_type', 16);
                $table->unsignedBigInteger('sender_id');
                $table->text('content');
                $table->json('attachment_file_ids')->nullable();
                $table->timestampsTz();
                $table->index(['support_ticket_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
