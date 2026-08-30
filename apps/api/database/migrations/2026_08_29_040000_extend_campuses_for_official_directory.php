<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->string('official_school_code', 32)->nullable()->unique()->after('code');
            $table->string('catalog_type', 24)->default('manual')->index()->after('name');
            $table->string('education_level', 40)->nullable()->index()->after('catalog_type');
            $table->string('supervising_authority', 120)->nullable()->after('education_level');
            $table->string('official_note', 255)->nullable()->after('supervising_authority');
            $table->string('catalog_status', 24)->default('manual')->index()->after('official_note');
            $table->string('catalog_version', 24)->nullable()->after('catalog_status');
            $table->string('catalog_source_url', 500)->nullable()->after('catalog_version');
            $table->timestamp('catalog_synced_at')->nullable()->after('catalog_source_url');
            $table->index(['province', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropIndex(['province', 'status']);
            $table->dropUnique(['official_school_code']);
            $table->dropColumn([
                'official_school_code',
                'catalog_type',
                'education_level',
                'supervising_authority',
                'official_note',
                'catalog_status',
                'catalog_version',
                'catalog_source_url',
                'catalog_synced_at',
            ]);
        });
    }
};
