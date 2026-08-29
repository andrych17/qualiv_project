<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Services\DueDiligenceService;
use App\Modules\Legal\Services\LandObjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalDueDiligenceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_flagged_check_blocks_until_overridden(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM',
                'certificate_number' => 'SHM-001',
                'address' => 'Jl. Contoh No. 1',
                'status' => LandObject::STATUS_ACTIVE,
            ]);

            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $service = app(DueDiligenceService::class);

            $check = $service->addCheck($landObject, DueDiligenceCheck::TYPE_BLOKIR_SENGKETA);
            $this->assertSame(DueDiligenceCheck::STATUS_PENDING, $check->status);
            $this->assertFalse($check->isBlocking());

            $check = $service->recordResult($check, DueDiligenceCheck::STATUS_FLAGGED, 'Ada blokir dari bank X', $userId);
            $this->assertTrue($check->isBlocking());

            $check = $service->override($check, 'Klien menerima risiko, blokir akan dilepas minggu depan', $userId);
            $this->assertFalse($check->isBlocking());
            $this->assertSame($userId, $check->overridden_by);

            $this->expectException(RuntimeException::class);
            $service->override($check, 'Cannot override twice', $userId);
        });
    }

    public function test_clear_result_never_blocks(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM',
                'certificate_number' => 'SHM-002',
                'address' => 'Jl. Contoh No. 2',
                'status' => LandObject::STATUS_ACTIVE,
            ]);

            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $service = app(DueDiligenceService::class);

            $check = $service->addCheck($landObject, DueDiligenceCheck::TYPE_PBB_PAYMENT_STATUS);
            $check = $service->recordResult($check, DueDiligenceCheck::STATUS_CLEAR, 'Lunas', $userId);

            $this->assertFalse($check->isBlocking());
        });
    }

    public function test_flagged_check_auto_triggers_one_site_visit(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            FieldVisitType::query()->create([
                'code' => 'site_survey', 'name' => 'Site Survey',
                'default_checklist' => ['Verify boundary markers'],
                'is_active' => true,
            ]);

            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM',
                'certificate_number' => 'SHM-003',
                'address' => 'Jl. Contoh No. 3',
                'status' => LandObject::STATUS_ACTIVE,
            ]);

            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $service = app(DueDiligenceService::class);

            $checkA = $service->addCheck($landObject, DueDiligenceCheck::TYPE_BLOKIR_SENGKETA);
            $service->recordResult($checkA, DueDiligenceCheck::STATUS_FLAGGED, 'Dispute reported', $userId);

            $this->assertSame(1, FieldVisit::query()->where('land_object_id', $landObject->id)->count());
            $visit = FieldVisit::query()->where('land_object_id', $landObject->id)->first();
            $this->assertSame(FieldVisit::STATUS_SCHEDULED, $visit->status);
            $this->assertStringContainsString('blokir_sengketa', $visit->notes);

            // A second check flagging on the same land object must not spawn a duplicate
            // while the auto-scheduled visit is still open.
            $checkB = $service->addCheck($landObject, DueDiligenceCheck::TYPE_SERTIFIKAT_VALIDITY);
            $service->recordResult($checkB, DueDiligenceCheck::STATUS_FLAGGED, 'Certificate mismatch', $userId);

            $this->assertSame(1, FieldVisit::query()->where('land_object_id', $landObject->id)->count());
        });
    }
}
