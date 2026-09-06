<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Services\BuktiPotongService;
use App\Modules\Accounting\Services\FakturPajakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3M — FakturPajakService/BuktiPotongService's own issuance edge cases and correction
 * flows (replace/cancel). issueOutput()/recordInput()/issue() themselves are already
 * exercised via ArInvoiceService::post()/ApBillService::post()'s taxable-line branches
 * (Phase 2); this file covers what only these services' own callers reach: number-block
 * exhaustion, duplicate input numbers, and the replace/cancel correction API no controller
 * calls yet (§3M: "corrections happen via a replacement/cancellation record").
 */
class FakturPajakAndBuktiPotongTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_issue_output_fails_loudly_with_no_active_block_and_when_a_block_is_exhausted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();

            try {
                app(FakturPajakService::class)->issueOutput($company->id, 1, $partner->id, null, 100, 11, now()->toDateString());
                $this->fail('Expected a ValidationException for no active block.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('nomor_seri_faktur', $e->errors());
            }

            $this->makeFakturPajakBlock($company, ['range_start' => 1, 'range_end' => 1]);
            app(FakturPajakService::class)->issueOutput($company->id, 1, $partner->id, null, 100, 11, now()->toDateString());

            try {
                app(FakturPajakService::class)->issueOutput($company->id, 2, $partner->id, null, 100, 11, now()->toDateString());
                $this->fail('Expected a ValidationException for an exhausted block.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('nomor_seri_faktur', $e->errors());
            }
        });
    }

    public function test_record_input_rejects_a_duplicate_faktur_number_for_the_same_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            app(FakturPajakService::class)->recordInput($company->id, 1, $partner->id, 'VENDOR-001', 100, 11, now()->toDateString());

            $this->expectException(ValidationException::class);
            app(FakturPajakService::class)->recordInput($company->id, 2, $partner->id, 'VENDOR-001', 200, 22, now()->toDateString());
        });
    }

    public function test_replace_and_cancel_an_output_faktur_and_reject_acting_on_a_non_issued_one(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $this->makeFakturPajakBlock($company);

            $service = app(FakturPajakService::class);
            $original = $service->issueOutput($company->id, 1, $partner->id, null, 1000000, 110000, now()->toDateString());

            $replacement = $service->replace($original, 900000, 99000);
            $this->assertSame(TaxFakturPajak::STATUS_REPLACED, $original->fresh()->status);
            $this->assertSame(TaxFakturPajak::STATUS_ISSUED, $replacement->status);
            $this->assertSame($original->id, $replacement->replaces_faktur_id);
            $this->assertNotSame($original->nomor_seri_faktur, $replacement->nomor_seri_faktur);

            $service->cancel($replacement);
            $this->assertSame(TaxFakturPajak::STATUS_CANCELLED, $replacement->fresh()->status);

            try {
                $service->cancel($replacement->fresh());
                $this->fail('Expected a ValidationException for cancelling an already-cancelled faktur.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            try {
                $service->replace($original->fresh(), 100, 11);
                $this->fail('Expected a ValidationException for replacing an already-replaced faktur.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_replace_an_input_faktur_requires_the_vendors_new_number(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $service = app(FakturPajakService::class);
            $original = $service->recordInput($company->id, 1, $partner->id, 'VENDOR-ORIG', 500000, 55000, now()->toDateString());

            try {
                $service->replace($original, 500000, 55000);
                $this->fail('Expected a ValidationException for a missing replacement number.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('nomor_seri_faktur', $e->errors());
            }

            $replacement = $service->replace($original->fresh(), 500000, 55000, 'VENDOR-NEW');
            $this->assertSame('VENDOR-NEW', $replacement->nomor_seri_faktur);
            $this->assertSame(TaxFakturPajak::DIRECTION_INPUT, $replacement->direction);
        });
    }

    public function test_bukti_potong_can_be_issued_replaced_and_cancelled(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $withholdingType = $this->makeWithholdingType($company);
            $partner = $this->makePartner();

            $service = app(BuktiPotongService::class);
            $first = $service->issue($company->id, 'BP23', 1, $withholdingType->id, $partner->id, 1000000, 20000, now()->toDateString());
            $second = $service->issue($company->id, 'BP23', 2, $withholdingType->id, $partner->id, 500000, 10000, now()->toDateString());

            // Gap-free sequence per (company, bp_type).
            $this->assertSame($first->sequence_no + 1, $second->sequence_no);

            $replacement = $service->replace($first, 900000, 18000);
            $this->assertSame(TaxBuktiPotong::STATUS_REPLACED, $first->fresh()->status);
            $this->assertSame($first->id, $replacement->replaces_bp_id);
            // A replacement draws its own new number too — never reuses the original's.
            $this->assertNotSame($first->bp_number, $replacement->bp_number);

            $service->cancel($second->fresh());
            $this->assertSame(TaxBuktiPotong::STATUS_CANCELLED, $second->fresh()->status);

            try {
                $service->replace($second->fresh(), 100, 10);
                $this->fail('Expected a ValidationException for replacing a cancelled Bukti Potong.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }
}
