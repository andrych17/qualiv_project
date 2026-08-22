<?php

namespace Tests\Feature;

use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use App\Modules\Legal\Services\FieldVisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalFieldVisitTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_visit_lifecycle_scheduled_to_completed(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $type = FieldVisitType::query()->create([
                'code' => 'site_survey_test', 'name' => 'Site Survey Test',
                'default_checklist' => ['Check boundary', 'Take photos'],
                'is_active' => true,
            ]);

            $service = app(FieldVisitService::class);
            $visit = $service->schedule(['visit_type_id' => $type->id]);
            $this->assertSame(FieldVisit::STATUS_SCHEDULED, $visit->status);

            $blank = $service->blankChecklist($type);
            $this->assertCount(2, $blank);
            $this->assertFalse($blank[0]['done']);

            $this->expectException(RuntimeException::class);
            $service->complete($visit, [], null);
        });
    }

    public function test_check_in_captures_gps_then_complete_stores_checklist(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $type = FieldVisitType::query()->create([
                'code' => 'site_survey_test2', 'name' => 'Site Survey Test 2',
                'default_checklist' => ['Check boundary'],
                'is_active' => true,
            ]);

            $service = app(FieldVisitService::class);
            $visit = $service->schedule(['visit_type_id' => $type->id]);

            $visit = $service->checkIn($visit, -6.200000, 106.816666);
            $this->assertSame(FieldVisit::STATUS_CHECKED_IN, $visit->status);
            $this->assertNotNull($visit->checked_in_at);
            $this->assertEquals(-6.2, (float) $visit->gps_lat);

            $this->expectException(RuntimeException::class);
            $service->checkIn($visit, -6.2, 106.8);
        });
    }

    public function test_complete_after_check_in(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $type = FieldVisitType::query()->create([
                'code' => 'site_survey_test3', 'name' => 'Site Survey Test 3',
                'default_checklist' => ['Check boundary'],
                'is_active' => true,
            ]);

            $service = app(FieldVisitService::class);
            $visit = $service->schedule(['visit_type_id' => $type->id]);
            $visit = $service->checkIn($visit, -6.2, 106.8);

            $result = [['label' => 'Check boundary', 'done' => true, 'note' => 'Clear']];
            $visit = $service->complete($visit, $result, 'All good');

            $this->assertSame(FieldVisit::STATUS_COMPLETED, $visit->status);
            $this->assertTrue($visit->checklist_result[0]['done']);
            $this->assertSame('All good', $visit->notes);
        });
    }
}
