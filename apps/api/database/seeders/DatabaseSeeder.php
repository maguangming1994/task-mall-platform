<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);
        $this->call(CampusSeeder::class);
        $this->call(ReleaseControlSeeder::class);
        $this->call(LegalDocumentSeeder::class);
        $this->call(SystemSettingDefinitionSeeder::class);
        $this->call(AfterSaleReasonSeeder::class);
        $this->call(SecondHandCategorySeeder::class);
        $this->call(MaterialSeeder::class);
        $this->call(PosterTemplateSeeder::class);
    }
}
