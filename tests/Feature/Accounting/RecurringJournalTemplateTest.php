<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\RecurringJournalTemplate;
use App\Modules\Accounting\Services\RecurringJournalTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3P — recurring journal templates; RecurringGenerationSweepTest covers actually drafting a GlJournal from one. */
class RecurringJournalTemplateTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_template(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $debitAccountId, $creditAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$debitAccountId, &$creditAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $debitAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $creditAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
        });

        $this->get("/accounting/recurring-journal-templates?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringJournalTemplates/Index'));
        $this->get("/accounting/recurring-journal-templates/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringJournalTemplates/Create'));
        // No company_id query param — formOptions()'s early-return branch.
        $this->get('/accounting/recurring-journal-templates/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', [])->where('costCenters', [])->where('currencies', []));

        $this->post('/accounting/recurring-journal-templates', [
            'company_id' => $companyId, 'name' => 'Monthly Rent', 'currency_code' => 'IDR',
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => 1000000],
                ['account_id' => $creditAccountId, 'credit' => 1000000],
            ],
        ])->assertRedirect();

        $templateId = null;
        $tenant->run(function () use (&$templateId, $companyId) {
            $template = RecurringJournalTemplate::query()->where('company_id', $companyId)->first();
            $templateId = $template->id;
            $this->assertSame('2026-01-05', $template->next_run_date->toDateString());
        });

        $this->get("/accounting/recurring-journal-templates/{$templateId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/RecurringJournalTemplates/Edit')
                ->has('template.lines', 2)->has('upcomingRunDates', 5));

        $this->put("/accounting/recurring-journal-templates/{$templateId}", [
            'name' => 'Monthly Rent (renamed)', 'currency_code' => 'IDR',
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => 1200000],
                ['account_id' => $creditAccountId, 'credit' => 1200000],
            ],
        ])->assertRedirect(route('accounting.recurring-journal-templates.edit', $templateId));

        $tenant->run(function () use ($templateId) {
            $template = RecurringJournalTemplate::query()->find($templateId);
            $this->assertSame('Monthly Rent (renamed)', $template->name);
            $this->assertEqualsWithDelta(1200000.0, (float) $template->lines->first()->debit, 0.01);
        });

        $this->post("/accounting/recurring-journal-templates/{$templateId}/set-active", ['is_active' => false])->assertRedirect();
        $tenant->run(function () use ($templateId) {
            $this->assertFalse(RecurringJournalTemplate::query()->find($templateId)->is_active);
        });

        $this->delete("/accounting/recurring-journal-templates/{$templateId}")->assertRedirect(route('accounting.recurring-journal-templates.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($templateId) {
            $this->assertNull(RecurringJournalTemplate::query()->find($templateId));
        });
    }

    public function test_store_rejects_an_invalid_rule_currency_and_line_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->post('/accounting/recurring-journal-templates', [
            'company_id' => 999999, 'name' => 'X', 'currency_code' => 'XXX',
            'recurrence_rule' => 'FREQ=NOTAFREQ', 'anchor_date' => '2026-01-05',
            'lines' => [['account_id' => 999999, 'cost_center_id' => 999999, 'debit' => 100, 'credit' => 100]],
        ])->assertSessionHasErrors(['company_id', 'recurrence_rule', 'currency_code', 'lines.0.account_id', 'lines.0.cost_center_id', 'lines.0.debit']);

        $this->post('/accounting/recurring-journal-templates', [
            'company_id' => $companyId, 'name' => 'X', 'currency_code' => 'IDR',
            'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['account_id' => $accountId]],
        ])->assertSessionHasErrors(['lines.0.debit']);

        // A truly omitted recurrence_rule (blocked by the FormRequest's own `required` rule,
        // but `after()` still runs) hits ValidatesRecurrenceRule's own null-guard branch.
        $this->post('/accounting/recurring-journal-templates', [
            'company_id' => $companyId, 'name' => 'X', 'currency_code' => 'IDR', 'anchor_date' => '2026-01-05',
            'lines' => [['account_id' => $accountId, 'debit' => 100], ['account_id' => $accountId, 'credit' => 100]],
        ])->assertSessionHasErrors(['recurrence_rule']);
    }

    public function test_update_rejects_invalid_line_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$templateId, $debitAccountId, $creditAccountId] = [null, null, null];
        $tenant->run(function () use (&$templateId, &$debitAccountId, &$creditAccountId) {
            $company = $this->makeCompany();
            $debitAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $creditAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $templateId = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debitAccountId, 'debit' => 100], ['account_id' => $creditAccountId, 'credit' => 100]],
                $this->adminUserId(),
            )->id;
        });

        $this->put("/accounting/recurring-journal-templates/{$templateId}", [
            'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['account_id' => 999999, 'cost_center_id' => 999999, 'debit' => 100, 'credit' => 100]],
        ])->assertSessionHasErrors(['lines.0.account_id', 'lines.0.cost_center_id', 'lines.0.debit']);

        $this->put("/accounting/recurring-journal-templates/{$templateId}", [
            'name' => 'X', 'currency_code' => 'XXX', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05',
            'lines' => [['account_id' => $debitAccountId]],
        ])->assertSessionHasErrors(['currency_code', 'lines.0.debit']);
    }

    public function test_service_rejects_a_line_with_both_debit_and_credit_and_an_unbalanced_set(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $account = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);

            try {
                app(RecurringJournalTemplateService::class)->create(
                    ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                    [['account_id' => $account->id, 'debit' => 100, 'credit' => 100]],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for a line with both debit and credit.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            try {
                app(RecurringJournalTemplateService::class)->create(
                    ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                    [['account_id' => $account->id, 'debit' => 100], ['account_id' => $account->id, 'credit' => 50]],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for an unbalanced template.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            try {
                app(RecurringJournalTemplateService::class)->create(
                    ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                    [['account_id' => $account->id]],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for a line with neither a debit nor a credit amount.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }
        });
    }

    /** RRULE with a COUNT bound so next_run_date eventually returns null (rule exhausted) — a re-edit of an already-run template re-derives it from last_run_date. */
    public function test_update_re_derives_next_run_date_from_the_last_run_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $account = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY;COUNT=2', 'anchor_date' => '2026-01-05'],
                [['account_id' => $account->id, 'debit' => 100], ['account_id' => $offsetAccount->id, 'credit' => 100]],
                $this->adminUserId(),
            );
            $template->update(['last_run_date' => '2026-01-05']);

            $updated = app(RecurringJournalTemplateService::class)->update(
                $template->fresh(),
                ['name' => 'X renamed'],
                [['account_id' => $account->id, 'debit' => 100], ['account_id' => $offsetAccount->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            $this->assertSame('2026-02-05', $updated->next_run_date->toDateString());
        });
    }
}
