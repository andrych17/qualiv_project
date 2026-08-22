<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\PartyRoleType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedPartyService;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\LandObjectService;
use App\Modules\Legal\Services\ProtocolBookService;
use App\Modules\Legal\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalTaxTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_generate_defaults_taxpayer_from_pihak_pertama_and_kedua(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'ajb_tax', 'name' => 'AJB Tax',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => true,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $seller = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Seller', 'source' => 'manual']);
            $buyer = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Buyer', 'source' => 'manual']);
            $pihakPertama = PartyRoleType::query()->create(['code' => 'pihak_pertama', 'name' => 'Pihak Pertama', 'is_active' => true]);
            $pihakKedua = PartyRoleType::query()->create(['code' => 'pihak_kedua', 'name' => 'Pihak Kedua', 'is_active' => true]);

            $deed = app(DeedService::class)->create([
                'deed_type_id' => $deedType->id,
                'transaction_value' => 1000000000,
            ]);

            $partyService = app(DeedPartyService::class);
            $partyService->add($deed, ['partner_id' => $seller->id, 'role_type_id' => $pihakPertama->id, 'identity_name' => 'Seller']);
            $partyService->add($deed, ['partner_id' => $buyer->id, 'role_type_id' => $pihakKedua->id, 'identity_name' => 'Buyer']);

            app(TaxService::class)->generateForDeed($deed);

            $pph = DeedTax::query()->where('deed_id', $deed->id)->where('tax_type', 'pph_final')->firstOrFail();
            $bphtb = DeedTax::query()->where('deed_id', $deed->id)->where('tax_type', 'bphtb')->firstOrFail();

            $this->assertSame($seller->id, $pph->taxpayer_partner_id);
            $this->assertSame($buyer->id, $bphtb->taxpayer_partner_id);
            $this->assertEquals(25000000.0, (float) $pph->computed_amount); // 2.5% of 1,000,000,000
            $this->assertEquals(50000000.0, (float) $bphtb->computed_amount); // 5% of 1,000,000,000

            $this->expectException(RuntimeException::class);
            app(TaxService::class)->generateForDeed($deed);
        });
    }

    public function test_signing_blocked_until_both_taxes_validated(): void
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
                'code' => 'ajb_tax_gate', 'name' => 'AJB Tax Gate',
                'category' => DeedType::CATEGORY_PPAT, 'requires_tax' => true,
                'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $landObject = app(LandObjectService::class)->create([
                'certificate_type' => 'SHM', 'certificate_number' => 'SHM-TAX-1',
                'address' => 'Jl. Pajak No. 1', 'status' => LandObject::STATUS_ACTIVE,
            ]);

            $deedService = app(DeedService::class);
            $deed = $deedService->create([
                'deed_type_id' => $deedType->id,
                'land_object_id' => $landObject->id,
                'transaction_value' => 200000000,
            ]);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $deedService->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            $taxService = app(TaxService::class);
            $taxService->generateForDeed($deed);

            try {
                $deedService->transition($deed, Deed::STATUS_SIGNED);
                $this->fail('Expected signing to be blocked by unvalidated tax obligations.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Tax obligation', $e->getMessage());
            }

            foreach (DeedTax::query()->where('deed_id', $deed->id)->get() as $tax) {
                $taxService->issueBillingCode($tax, 'BILLING-'.$tax->id);
                $taxService->markPaid($tax, 'NTPN-'.$tax->id);
                $taxService->markValidated($tax);
            }

            $deed = $deedService->transition($deed, Deed::STATUS_SIGNED);
            $this->assertSame(Deed::STATUS_SIGNED, $deed->status);
        });
    }
}
