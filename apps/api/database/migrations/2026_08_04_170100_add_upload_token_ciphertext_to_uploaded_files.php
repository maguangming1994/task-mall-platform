<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_files', fn (Blueprint $table) => $table->text('upload_token_ciphertext')->nullable()->after('upload_token_hash'));
    }

    public function down(): void
    {
        Schema::table('uploaded_files', fn (Blueprint $table) => $table->dropColumn('upload_token_ciphertext'));
    }
};
