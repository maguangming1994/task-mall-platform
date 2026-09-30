<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->string('placeholder_title_reviewing', 160)->nullable()->after('audience');
            $table->text('placeholder_desc_reviewing')->nullable()->after('placeholder_title_reviewing');
            $table->string('placeholder_title_reviewed', 160)->nullable()->after('placeholder_desc_reviewing');
            $table->text('placeholder_desc_reviewed')->nullable()->after('placeholder_title_reviewed');
        });
    }

    public function down(): void
    {
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->dropColumn(['placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed']);
        });
    }
};
