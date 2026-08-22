<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\DueDiligenceCheck;
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
}
