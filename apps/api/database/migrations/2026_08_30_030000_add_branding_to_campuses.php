<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table): void {
            $table->char('emblem_asset_id', 26)->nullable()->after('longitude');
            $table->string('emblem_url', 2048)->nullable()->after('emblem_asset_id');
            $table->char('logo_asset_id', 26)->nullable()->after('emblem_url');
            $table->string('logo_url', 2048)->nullable()->after('logo_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table): void {
            $table->dropColumn(['emblem_asset_id', 'emblem_url', 'logo_asset_id', 'logo_url']);
        });
    }
};
