<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Accounting\Services\ArPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — customer payment application: create()+post() run together from the guided HTTP form (no separate post-only or destroy route). */
class ArPaymentTest extends TestCase
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

    public function test_admin_can_record_and_post_a_payment_with_explicit_applications(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $invoiceId, $cashAccountId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$invoiceId, &$cashAccountId) {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner();
            $partnerId = $partner->id;
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;

            $invoice = app(ArInvoiceService::class)->create(
                ['company_id' => $companyId, 'partner_id' => $partnerId, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 200000, 'revenue_account_id' => $revenueAccount->id]],
                null,
            );
            app(ArInvoiceService::class)->post($invoice, $this->adminUserId());
            $invoiceId = $invoice->id;
        });

        $this->get("/accounting/ar-payments?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArPayments/Index'));
        $this->get("/accounting/ar-payments/create?company_id={$companyId}&partner_id={$partnerId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArPayments/Create')->has('openInvoices', 1));
        $this->getJson("/accounting/ar-payments/open-invoices?company_id={$companyId}&partner_id={$partnerId}")->assertOk()->assertJsonCount(1);
        // No company_id query param — ArPaymentController::cashAccounts()'s early-return branch.
        $this->get('/accounting/ar-payments/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('cashAccounts', []));

        $this->post('/accounting/ar-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 200000,
            'applications' => [['ar_invoice_id' => $invoiceId, 'applied_amount' => 200000]],
        ])->assertRedirect(route('accounting.ar-payments.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($invoiceId, $companyId) {
            $payment = ArPayment::query()->where('company_id', $companyId)->first();
            $this->assertSame(ArPayment::STATUS_POSTED, $payment->status);
            $this->assertNotNull($payment->journal_id);

            $invoice = ArInvoice::query()->find($invoiceId);
            $this->assertSame(ArInvoice::STATUS_PAID, $invoice->status);
            $this->assertEqualsWithDelta(0.0, $invoice->openBalance(), 0.01);
        });
    }

    public function test_payment_auto_applies_oldest_due_first_when_applications_omitted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $olderInvoiceId, $newerInvoiceId, $cashAccountId] = [null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$olderInvoiceId, &$newerInvoiceId, &$cashAccountId) {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner();
            $partnerId = $partner->id;
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;

            $invoices = app(ArInvoiceService::class);
            $older = $invoices->create(['company_id' => $companyId, 'partner_id' => $partnerId, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($older, $this->adminUserId());
            $olderInvoiceId = $older->id;

            $newer = $invoices->create(['company_id' => $companyId, 'partner_id' => $partnerId, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-02-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($newer, $this->adminUserId());
            $newerInvoiceId = $newer->id;
        });

        // Pays only the older (earlier-due) invoice in full — the newer one stays open.
        $this->post('/accounting/ar-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000,
        ])->assertRedirect();

        $tenant->run(function () use ($olderInvoiceId, $newerInvoiceId) {
            $this->assertSame(ArInvoice::STATUS_PAID, ArInvoice::query()->find($olderInvoiceId)->status);
            $this->assertSame(ArInvoice::STATUS_POSTED, ArInvoice::query()->find($newerInvoiceId)->status);
        });
    }

    public function test_store_rejects_invalid_company_partner_cash_account_currency_and_bad_applications(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $cashAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$cashAccountId) {
            $arAccount = $this->setUpCompanyWithControl();
            $companyId = $arAccount->company_id;
            $partnerId = $this->makePartner()->id;
            $cashAccountId = $this->makeAccount($arAccount->company, ['account_type' => Account::TYPE_ASSET])->id;
        });

        $this->post('/accounting/ar-payments', [
            'company_id' => 999999, 'partner_id' => 999999, 'cash_gl_account_id' => 999999, 'currency_code' => 'XXX',
            'payment_date' => '2026-01-10', 'amount' => 100, 'applications' => [['ar_invoice_id' => 999999, 'applied_amount' => 100]],
        ])->assertSessionHasErrors(['company_id', 'partner_id', 'cash_gl_account_id', 'currency_code', 'applications.0.ar_invoice_id']);

        // Explicitly empty applications array (not omitted) — reaches assertApplicationsValid's "needs at least one" guard.
        $this->post('/accounting/ar-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100, 'applications' => [],
        ])->assertSessionHasErrors(['applications']);
    }

    public function test_auto_apply_throws_when_amount_exceeds_total_open_balance(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $this->expectException(ValidationException::class);
            app(ArPaymentService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id,
                'cash_gl_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 999999,
            ], null, null);
        });
    }

    public function test_assert_applications_valid_rejects_amount_mismatch_wrong_partner_wrong_currency_and_over_application(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $otherPartner = $this->makePartner(['name' => 'Other']);
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $foreignInvoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'USD', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($foreignInvoice, $this->adminUserId());

            $svc = app(ArPaymentService::class);

            // Sum of applications doesn't match the payment amount.
            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 50000]], null);
                $this->fail('Expected a ValidationException for amount mismatch.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            // Invoice belongs to a different partner than the payment.
            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $otherPartner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 100000]], null);
                $this->fail('Expected a ValidationException for wrong partner.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            // Invoice currency doesn't match the payment's currency.
            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100], [['ar_invoice_id' => $foreignInvoice->id, 'applied_amount' => 100]], null);
                $this->fail('Expected a ValidationException for currency mismatch.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            // Applied amount exceeds the invoice's open balance.
            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 500000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 500000]], null);
                $this->fail('Expected a ValidationException for over-application.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }
        });
    }

    public function test_post_rejects_missing_control_account_already_posted_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $partner = $this->makePartner();
            $invoices = app(ArInvoiceService::class);
            $payments = app(ArPaymentService::class);

            // A payment with no application target would never even reach post() (create()'s
            // own "needs at least one invoice application" guard fires first) — so posting
            // this company's own control-account guard needs a real open invoice, which in
            // turn needs the control account to post. Give it one, post the invoice, then
            // remove it before testing the PAYMENT's own guard.
            $companyNoControl = $this->makeCompany(['legal_name' => 'No Control']);
            $tempArAccount = $this->makeAccount($companyNoControl, ['is_control_account' => true]);
            $companyNoControl->update(['ar_control_account_id' => $tempArAccount->id]);
            $this->makeFiscalYear($companyNoControl);
            $revenueAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_REVENUE]);
            $invoice = $invoices->create(['company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());
            $companyNoControl->update(['ar_control_account_id' => null]);
            $cashAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_ASSET]);

            $payment = $payments->create([
                'company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 100]], null);

            try {
                $payments->post($payment, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing AR control account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('company_id', $e->errors());
            }

            // Already-posted payment can't be posted again.
            $arAccount = $this->setUpCompanyWithControl(['legal_name' => 'With Control']);
            $company = $arAccount->company;
            $revenueAccount2 = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoice2 = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount2->id]], null);
            $invoices->post($invoice2, $this->adminUserId());
            $postedPayment = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ar_invoice_id' => $invoice2->id, 'applied_amount' => 100]], null);
            $payments->post($postedPayment, $this->adminUserId());

            try {
                $payments->post($postedPayment->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted payment.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            // Fiscal year covers 2026 only — a payment dated in 2027 has no open period.
            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $arAccount2 = $this->makeAccount($companyNoPeriod, ['is_control_account' => true]);
            $companyNoPeriod->update(['ar_control_account_id' => $arAccount2->id]);
            $this->makeFiscalYear($companyNoPeriod);
            $revenueAccount3 = $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_REVENUE]);
            $invoice3 = $invoices->create(['company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount3->id]], null);
            $invoices->post($invoice3, $this->adminUserId());
            $noPeriodPayment = $payments->create([
                'company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2027-06-01', 'amount' => 100,
            ], [['ar_invoice_id' => $invoice3->id, 'applied_amount' => 100]], null);

            try {
                $payments->post($noPeriodPayment, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('payment_date', $e->errors());
            }
        });
    }

    /** A foreign-currency payment exercises post()'s own isForeign fxTrio branch (distinct from the invoice's own, tested elsewhere). */
    public function test_post_a_foreign_currency_payment_records_the_fx_trio(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);

            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'USD', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $payment = app(ArPaymentService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'USD', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 100]], null);
            $payment = app(ArPaymentService::class)->post($payment, $this->adminUserId());

            $line = $payment->journal->lines()->where('account_id', $cashAccount->id)->first();
            $this->assertSame('USD', $line->fx_currency_code);
            $this->assertEqualsWithDelta(100.0, (float) $line->fx_amount, 0.01);
        });
    }

    /** ArPaymentService::delete() has no HTTP route (payments only create()+post() together) — direct service coverage. */
    public function test_delete_is_allowed_only_while_draft(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $arAccount = $this->setUpCompanyWithControl();
            $company = $arAccount->company;
            $partner = $this->makePartner();
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoices = app(ArInvoiceService::class);
            $payments = app(ArPaymentService::class);

            $invoiceForDraft = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoiceForDraft, $this->adminUserId());
            $draft = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ar_invoice_id' => $invoiceForDraft->id, 'applied_amount' => 100]], null);
            $payments->delete($draft);
            $this->assertNull(ArPayment::query()->find($draft->id));

            $invoiceForPosted = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoiceForPosted, $this->adminUserId());
            $posted = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ar_invoice_id' => $invoiceForPosted->id, 'applied_amount' => 100]], null);
            $payments->post($posted, $this->adminUserId());

            $this->expectException(ValidationException::class);
            $payments->delete($posted->fresh());
        });
    }
}
