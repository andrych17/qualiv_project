<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\RecurringArTemplate;
use App\Modules\Accounting\Services\RecurringArTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3P — recurring AR invoice templates (e.g. a monthly retainer); RecurringGenerationSweepTest covers actually drafting an ArInvoice from one. */
class RecurringArTemplateTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_template(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $revenueAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$revenueAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $partnerId = $this->makePartner()->id;
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        $this->get("/accounting/recurring-ar-templates?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringArTemplates/Index'));
        $this->get("/accounting/recurring-ar-templates/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringArTemplates/Create')->where('invoiceTypes', ArInvoice::TYPES));
        $this->get('/accounting/recurring-ar-templates/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('revenueAccounts', [])->where('taxCodes', [])->where('currencies', []));

        $this->post('/accounting/recurring-ar-templates', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'name' => 'Monthly Retainer', 'currency_code' => 'IDR',
            'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30,
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['description' => 'Retainer', 'qty' => 1, 'unit_price' => 5000000, 'revenue_account_id' => $revenueAccountId]],
        ])->assertRedirect();

        $templateId = null;
        $tenant->run(function () use (&$templateId, $companyId) {
            $template = RecurringArTemplate::query()->where('company_id', $companyId)->first();
            $templateId = $template->id;
            $this->assertSame('2026-01-05', $template->next_run_date->toDateString());
        });

        $this->get("/accounting/recurring-ar-templates/{$templateId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringArTemplates/Edit')
                ->has('template.lines', 1)->has('upcomingRunDates', 5)->where('template.partner_name', fn ($v) => $v !== null));

        $this->put("/accounting/recurring-ar-templates/{$templateId}", [
            'partner_id' => $partnerId, 'name' => 'Monthly Retainer (renamed)', 'currency_code' => 'IDR',
            'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 14,
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['description' => 'Retainer', 'qty' => 1, 'unit_price' => 6000000, 'revenue_account_id' => $revenueAccountId]],
        ])->assertRedirect(route('accounting.recurring-ar-templates.edit', $templateId));

        $tenant->run(function () use ($templateId) {
            $template = RecurringArTemplate::query()->find($templateId);
            $this->assertSame('Monthly Retainer (renamed)', $template->name);
            $this->assertSame(14, $template->payment_terms_days);
        });

        $this->post("/accounting/recurring-ar-templates/{$templateId}/set-active", ['is_active' => false])->assertRedirect();
        $tenant->run(function () use ($templateId) {
            $this->assertFalse(RecurringArTemplate::query()->find($templateId)->is_active);
        });

        $this->delete("/accounting/recurring-ar-templates/{$templateId}")->assertRedirect(route('accounting.recurring-ar-templates.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($templateId) {
            $this->assertNull(RecurringArTemplate::query()->find($templateId));
        });
    }

    public function test_store_rejects_an_invalid_rule_partner_currency_and_line_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/recurring-ar-templates', [
            'company_id' => 999999, 'partner_id' => 999999, 'name' => 'X', 'currency_code' => 'XXX',
            'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30,
            'recurrence_rule' => 'FREQ=NOTAFREQ', 'anchor_date' => '2026-01-05',
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'revenue_account_id' => 999999]],
        ])->assertSessionHasErrors(['company_id', 'recurrence_rule', 'partner_id', 'currency_code', 'lines.0.tax_code_id', 'lines.0.revenue_account_id']);
    }

    public function test_update_rejects_invalid_partner_and_line_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$templateId, $revenueAccountId] = [null, null];
        $tenant->run(function () use (&$templateId, &$revenueAccountId) {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
            $templateId = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'X', 'currency_code' => 'IDR', 'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccountId]],
                $this->adminUserId(),
            )->id;
        });

        $this->put("/accounting/recurring-ar-templates/{$templateId}", [
            'partner_id' => 999999, 'name' => 'X', 'currency_code' => 'XXX',
            'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30,
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'revenue_account_id' => 999999]],
        ])->assertSessionHasErrors(['partner_id', 'currency_code', 'lines.0.tax_code_id', 'lines.0.revenue_account_id']);
    }

    public function test_service_rejects_an_empty_line_list(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $template = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'X', 'currency_code' => 'IDR', 'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id]],
                $this->adminUserId(),
            );

            $this->expectException(ValidationException::class);
            app(RecurringArTemplateService::class)->update($template, ['name' => 'X'], [], $this->adminUserId());
        });
    }
}
