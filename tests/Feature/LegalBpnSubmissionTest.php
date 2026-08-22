<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\BpnSubmissionService;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\LandObjectService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalBpnSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_signing_auto_creates_pending_bpn_submission(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => $notaryId,
            ]);

            $deedType = DeedType::query()->create([
                'code' => 'ajb_bpn', 'name' => 'AJB BPN',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => false,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM', 'certificate_number' => 'SHM-BPN-1',
                'address' => 'Jl. BPN No. 1', 'status' => LandObject::STATUS_ACTIVE,
            ]);

            $deedService = app(DeedService::class);
            $deed = $deedService->create([
                'deed_type_id' => $deedType->id,
                'land_object_id' => $landObject->id,
                'transaction_value' => 1000000,
            ]);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $deedService->transition($deed, Deed::STATUS_READY_FOR_SIGNING);
            $deed = $deedService->transition($deed, Deed::STATUS_SIGNED);

            $submission = BpnSubmission::query()->where('deed_id', $deed->id)->firstOrFail();
            $this->assertSame(BpnSubmission::STATUS_PREPARED, $submission->status);
            $this->assertSame(BpnSubmission::TYPE_BALIK_NAMA, $submission->submission_type);
            // (1,000,000 / 1000) + 50,000 = 51,000
            $this->assertEquals(51000.0, (float) $submission->pnbp_amount);
        });
    }

    public function test_rejection_and_resubmission_chain(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'ajb_bpn2', 'name' => 'AJB BPN 2',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => false,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $deed = app(DeedService::class)->create(['deed_type_id' => $deedType->id, 'transaction_value' => 5000000]);

            $service = app(BpnSubmissionService::class);
            $submission = $service->createPending($deed, BpnSubmission::TYPE_BALIK_NAMA);
            $submission = $service->submit($submission, 'TRK-001');
            $submission = $service->markInProcess($submission);
            $submission = $service->reject($submission, 'Dokumen kurang lengkap');

            $this->assertSame(BpnSubmission::STATUS_REJECTED, $submission->status);

            $resubmission = $service->resubmit($submission);
            $this->assertSame(BpnSubmission::STATUS_PREPARED, $resubmission->status);
            $this->assertSame($submission->id, $resubmission->resubmission_of_id);

            $this->expectException(RuntimeException::class);
            $service->resubmit($submission);
        });
    }
}
