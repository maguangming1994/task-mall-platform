<?php

namespace Tests\Feature;

use App\Modules\Campus\Models\Campus;
use App\Modules\Major\Models\CampusMajor;
use Database\Seeders\MajorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MajorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_campus_type_prioritizes_related_categories(): void
    {
        $this->seed(MajorSeeder::class);

        $campus = $this->createCampus('jxau', '江西农业大学');

        $response = $this->getJson('/api/v1/majors?campus_id='.$campus->public_id.'&limit=12');

        $response->assertOk()->assertJsonPath('code', 'OK');

        $data = $response->json('data');
        $this->assertContains('农学', $data['recommended_categories']);
        $this->assertSame('catalog', $data['source']);
        $this->assertSame('农学', $data['items'][0]['category']);
        $this->assertSame('农学', $data['groups'][0]['category']);
    }

    public function test_without_campus_keeps_catalog_order(): void
    {
        $this->seed(MajorSeeder::class);

        $response = $this->getJson('/api/v1/majors?limit=1');

        $response->assertOk();
        $this->assertSame('哲学', $response->json('data.items.0.category'));
        $this->assertSame([], $response->json('data.recommended_categories'));
        $this->assertSame('catalog', $response->json('data.source'));
    }

    public function test_campus_with_own_majors_returns_only_school_majors(): void
    {
        $campus = $this->createCampus('jxau', '江西农业大学');
        $this->createCampusMajors($campus);

        $response = $this->getJson('/api/v1/majors?campus_id='.$campus->public_id.'&limit=20');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('campus', $data['source']);
        $this->assertSame('江西农业大学', $data['campus_name']);
        // 农业类院校优先展示农学门类，再按原始顺序展示其他专业
        $this->assertSame(['农学', '园艺', '计算机科学与技术'], array_column($data['items'], 'name'));
        $this->assertSame('农学', $data['items'][0]['category']);
        $this->assertSame(['农学', '工学'], array_column($data['groups'], 'category'));
    }

    public function test_campus_major_keyword_search_returns_matching_school_majors(): void
    {
        $campus = $this->createCampus('jxau', '江西农业大学');
        $this->createCampusMajors($campus);

        $response = $this->getJson('/api/v1/majors?campus_id='.$campus->public_id.'&keyword=园');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('campus', $data['source']);
        $this->assertSame(['园艺'], array_column($data['items'], 'name'));
        $this->assertNull($data['groups']);
    }

    public function test_import_command_writes_campus_majors(): void
    {
        $campus = Campus::query()->create([
            'public_id' => 'jxau',
            'code' => 'JXAU',
            'official_school_code' => '4136010410',
            'name' => '江西农业大学',
            'catalog_type' => 'manual',
            'catalog_status' => 'manual',
        ]);

        $file = tempnam(sys_get_temp_dir(), 'campus-majors');
        file_put_contents($file, json_encode([
            'metadata' => ['source_name' => '测试数据', 'fetched_at' => '2026-11-26T00:00:00Z'],
            'schools' => [[
                'official_school_code' => '4136010410',
                'name' => '江西农业大学',
                'province' => '江西省',
                'majors' => [
                    ['name' => '农学', 'code' => '090101', 'category' => '农学', 'level' => '本科(普通)'],
                    ['name' => '园艺', 'code' => '090102', 'category' => '农学', 'level' => '本科(普通)'],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $this->artisan('campus:majors:import', ['--file' => $file, '--fresh' => true])->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $this->assertDatabaseHas('campus_majors', [
            'campus_id' => $campus->id,
            'major_name' => '农学',
            'category' => '农学',
        ]);
        $this->assertSame(2, CampusMajor::query()->where('campus_id', $campus->id)->count());
    }

    private function createCampus(string $publicId, string $name): Campus
    {
        return Campus::query()->create([
            'public_id' => $publicId,
            'code' => strtoupper($publicId),
            'name' => $name,
            'catalog_type' => 'manual',
            'catalog_status' => 'manual',
        ]);
    }

    private function createCampusMajors(Campus $campus): void
    {
        CampusMajor::query()->create([
            'campus_id' => $campus->id,
            'major_name' => '农学',
            'major_code' => '090101',
            'category' => '农学',
            'level' => '本科(普通)',
            'sort_order' => 0,
        ]);
        CampusMajor::query()->create([
            'campus_id' => $campus->id,
            'major_name' => '园艺',
            'major_code' => '090102',
            'category' => '农学',
            'level' => '本科(普通)',
            'sort_order' => 1,
        ]);
        CampusMajor::query()->create([
            'campus_id' => $campus->id,
            'major_name' => '计算机科学与技术',
            'major_code' => '080901',
            'category' => '工学',
            'level' => '本科(普通)',
            'sort_order' => 2,
        ]);
    }
}
