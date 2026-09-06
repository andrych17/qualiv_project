<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArCreditNote;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Services\ArCreditNoteService;
use App\Modules\Accounting\Services\ArInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D credit notes (v1: invoice-linked only, issued+posted inline from the invoice Show page) and AR Aging. */
class ArCreditNoteAndAgingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function setUpCompanyWithControl(array $attrs = []): Account
    {
        $company = $this->makeCompany($attrs);
        $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
        $company->update(['ar_control_account_id' => $arAccount->id]);
        $this->makeFiscalYear($company);

        return $arAccount;
    }

    public function test_admin_can_issue_and_post_a_credit_note_against_an_invoice(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$invoiceId, $revenueAccountId] = [null, null];
        $tenant->run(function () use (&$invoiceId, &$revenueAccountId) {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;

            $invoice = app(ArInvoiceService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 300000, 'revenue_account_id' => $revenueAccountId]],
                null,
            );
            app(ArInvoiceService::class)->post($invoice, $this->adminUserId());
            $invoiceId = $invoice->id;
        });

        $this->post('/accounting/ar-credit-notes', [
            'ar_invoice_id' => $invoiceId, 'credit_date' => '2026-01-10', 'amount' => 100000,
            'reason' => 'Partial return', 'revenue_account_id' => $revenueAccountId,
        ])->assertRedirect(route('accounting.ar-invoices.show', $invoiceId));

        $tenant->run(function () use ($invoiceId) {
            $note = ArCreditNote::query()->where('ar_invoice_id', $invoiceId)->first();
            $this->assertNotNull($note);
            $this->assertSame(ArCreditNote::STATUS_POSTED, $note->status);
            $this->assertNotNull($note->journal_id);

            $invoice = ArInvoice::query()->find($invoiceId);
            $this->assertEqualsWithDelta(100000.0, (float) $invoice->credited_amount, 0.01);
            $this->assertEqualsWithDelta(200000.0, $invoice->openBalance(), 0.01);
            $this->assertSame(ArInvoice::STATUS_PARTIALLY_PAID, $invoice->status);
        });
    }

    public function test_store_rejects_invalid_invoice_and_revenue_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/ar-credit-notes', [
            'ar_invoice_id' => 999999, 'credit_date' => '2026-01-10', 'amount' => 100000, 'revenue_account_id' => 999999,
        ])->assertSessionHasErrors(['ar_invoice_id', 'revenue_account_id']);
    }

    public function test_post_rejects_amount_exceeding_open_balance_and_missing_control_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $this->post('/accounting/ar-credit-notes', [
                'ar_invoice_id' => $invoice->id, 'credit_date' => '2026-01-10', 'amount' => 999999, 'revenue_account_id' => $revenueAccount->id,
            ])->assertSessionHasErrors(['amount']);

            // Missing AR control account is only reachable via the service layer directly —
            // the invoice itself needed a control account to post in the first place, so
            // remove it afterward, same trick as ArPaymentTest's equivalent guard test.
            $company->update(['ar_control_account_id' => null]);
            $svc = app(ArCreditNoteService::class);
            $note = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'ar_invoice_id' => $invoice->id, 'credit_date' => '2026-01-10', 'amount' => 50000, 'revenue_account_id' => $revenueAccount->id], null);

            try {
                $svc->post($note, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing AR control account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('company_id', $e->errors());
            }

            // Already-posted credit note can't be posted again.
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $note2 = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'credit_date' => '2026-01-10', 'amount' => 1000, 'revenue_account_id' => $revenueAccount->id], null);
            $svc->post($note2, $this->adminUserId());
            try {
                $svc->post($note2->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted credit note.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            // No open fiscal period covers the credit note's date.
            $note3 = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'credit_date' => '2027-06-01', 'amount' => 1000, 'revenue_account_id' => $revenueAccount->id], null);
            try {
                $svc->post($note3, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('credit_date', $e->errors());
            }
        });
    }

    public function test_create_rejects_a_foreign_currency_invoice(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'USD', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $this->expectException(ValidationException::class);
            app(ArCreditNoteService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'ar_invoice_id' => $invoice->id,
                'credit_date' => '2026-01-10', 'amount' => 10, 'revenue_account_id' => $revenueAccount->id,
            ], null);
        });
    }

    public function test_ar_aging_buckets_open_invoices_by_days_past_due(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        Carbon::setTestNow('2026-06-15');

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner(['name' => 'Aging Partner']);
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoices = app(ArInvoiceService::class);

            // Current (not yet due).
            $current = $invoices->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-07-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($current, $this->adminUserId());

            // 14 days past due -> days_1_30 bucket.
            $overdue1to30 = $invoices->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-06-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 150, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($overdue1to30, $this->adminUserId());

            // 45 days past due -> days_31_60 bucket.
            $overdue = $invoices->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-05-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 200, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($overdue, $this->adminUserId());

            // 75 days past due -> days_61_90 bucket.
            $overdue61to90 = $invoices->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-04-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 300, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($overdue61to90, $this->adminUserId());

            // Well past 90 days -> days_90_plus bucket.
            $overdue90plus = $invoices->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 400, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($overdue90plus, $this->adminUserId());
        });

        $this->get("/accounting/ar-aging?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArAging/Index')
                ->has('rows', 1)
                ->where('rows.0.current', 100)
                ->where('rows.0.days_1_30', 150)
                ->where('rows.0.days_31_60', 200)
                ->where('rows.0.days_61_90', 300)
                ->where('rows.0.days_90_plus', 400));

        Carbon::setTestNow();
    }
}
