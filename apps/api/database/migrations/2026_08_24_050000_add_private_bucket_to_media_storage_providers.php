<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_storage_providers', function (Blueprint $table): void {
            $table->string('private_bucket', 120)->nullable()->after('bucket');
        });
    }

    public function down(): void
    {
        Schema::table('media_storage_providers', function (Blueprint $table): void {
            $table->dropColumn('private_bucket');
        });
    }
};
