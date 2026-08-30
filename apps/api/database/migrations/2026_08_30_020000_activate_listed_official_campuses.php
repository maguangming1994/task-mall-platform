<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('campuses')
            ->whereNotNull('official_school_code')
            ->whereNotNull('catalog_synced_at')
            ->where('catalog_status', 'listed')
            ->where('status', 'disabled')
            ->whereColumn('updated_at', 'catalog_synced_at')
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data corrections are intentionally irreversible.
    }
};
