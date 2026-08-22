<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\DueDiligenceService;
use App\Modules\Legal\Services\LandObjectService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalPpatDeedTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function openBook(int $notaryId): void
    {
        app(ProtocolBookService::class)->open([
            'book_type' => ProtocolBook::TYPE_REPERTORIUM,
            'year' => (int) now()->format('Y'),
            'notary_user_id' => $notaryId,
        ]);
    }

    public function test_ppat_deed_requires_land_object_before_signing(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $this->openBook(User::query()->where('email', 'admin@nusaevo.com')->value('id'));

            $deedType = DeedType::query()->create([
                'code' => 'ajb_test', 'name' => 'AJB Test',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => true,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);

            $service = app(DeedService::class);
            $deed = $service->create(['deed_type_id' => $deedType->id]);
            $this->assertSame(DeedType::CATEGORY_PPAT, $deed->category);

            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $service->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            $this->expectException(RuntimeException::class);
            $service->transition($deed, Deed::STATUS_SIGNED);
        });
    }

    public function test_flagged_due_diligence_blocks_signing_until_overridden(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $this->openBook($userId);

            // requires_tax=false here — this test isolates the due-diligence half of the
            // gate; the tax half is covered by LegalTaxTest.
            $deedType = DeedType::query()->create([
                'code' => 'ajb_test2', 'name' => 'AJB Test 2',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => false,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM', 'certificate_number' => 'SHM-PPAT-1',
                'address' => 'Jl. Tanah No. 1', 'status' => LandObject::STATUS_ACTIVE,
            ]);

            $ddService = app(DueDiligenceService::class);
            $check = $ddService->addCheck($landObject, DueDiligenceCheck::TYPE_BLOKIR_SENGKETA);
            $check = $ddService->recordResult($check, DueDiligenceCheck::STATUS_FLAGGED, 'Ada sengketa', $userId);

            $deedService = app(DeedService::class);
            $deed = $deedService->create([
                'deed_type_id' => $deedType->id,
                'land_object_id' => $landObject->id,
                'transaction_value' => 500000000,
            ]);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $deedService->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            try {
                $deedService->transition($deed, Deed::STATUS_SIGNED);
                $this->fail('Expected signing to be blocked by the flagged due-diligence check.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('due-diligence', $e->getMessage());
            }

            $ddService->override($check, 'Klien menerima risiko', $userId);

            $deed = $deedService->transition($deed, Deed::STATUS_SIGNED);
            $this->assertSame(Deed::STATUS_SIGNED, $deed->status);
            $this->assertNotNull($deed->deed_number);
        });
    }
}
