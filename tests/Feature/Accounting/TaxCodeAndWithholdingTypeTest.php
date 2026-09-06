<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Models\WithholdingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3M — PPN tax codes and PPh withholding types, both plain company-scoped CRUD. */
class TaxCodeAndWithholdingTypeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_tax_code(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
        });

        $this->get("/accounting/tax-codes?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/TaxCodes/Index'));
        $this->get("/accounting/tax-codes/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/TaxCodes/Create'));
        // No company_id query param — accountOptions()'s early-return branch.
        $this->get('/accounting/tax-codes/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/tax-codes', [
            'company_id' => $companyId, 'code' => 'PPN11', 'rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT,
            'gl_account_id' => $accountId,
        ])->assertRedirect(route('accounting.tax-codes.index', ['company_id' => $companyId]));

        $taxCodeId = null;
        $tenant->run(function () use (&$taxCodeId, $companyId) {
            $taxCodeId = TaxCode::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/tax-codes/{$taxCodeId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/TaxCodes/Edit'));

        $this->put("/accounting/tax-codes/{$taxCodeId}", [
            'code' => 'PPN11', 'rate' => 12, 'tax_type' => TaxCode::TYPE_OUTPUT, 'gl_account_id' => $accountId, 'is_active' => true,
        ])->assertRedirect(route('accounting.tax-codes.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($taxCodeId) {
            $this->assertEqualsWithDelta(12.0, (float) TaxCode::query()->find($taxCodeId)->rate, 0.01);
        });

        $this->delete("/accounting/tax-codes/{$taxCodeId}")->assertRedirect(route('accounting.tax-codes.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($taxCodeId) {
            $this->assertNull(TaxCode::query()->find($taxCodeId));
        });
    }

    public function test_tax_code_store_and_update_reject_invalid_references_and_duplicate_codes(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId, $existingId, $otherId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$accountId, &$existingId, &$otherId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $existingId = $this->makeTaxCode($company, ['code' => 'PPN11', 'gl_account_id' => $accountId])->id;
            $otherId = $this->makeTaxCode($company, ['code' => 'PPN12', 'gl_account_id' => $accountId])->id;
        });

        $this->post('/accounting/tax-codes', [
            'company_id' => 999999, 'code' => 'X', 'rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT, 'gl_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'gl_account_id']);

        $this->post('/accounting/tax-codes', [
            'company_id' => $companyId, 'code' => 'PPN11', 'rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT, 'gl_account_id' => $accountId,
        ])->assertSessionHasErrors(['code']);

        $this->put("/accounting/tax-codes/{$existingId}", [
            'code' => 'PPN11', 'rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT, 'gl_account_id' => 999999,
        ])->assertSessionHasErrors(['gl_account_id']);

        // Renaming $otherId to $existingId's own code is a duplicate on UPDATE, not just on create.
        $this->put("/accounting/tax-codes/{$otherId}", [
            'code' => 'PPN11', 'rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT, 'gl_account_id' => $accountId,
        ])->assertSessionHasErrors(['code']);
    }

    public function test_admin_can_crud_a_withholding_type(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
        });

        $this->get("/accounting/withholding-types?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/WithholdingTypes/Index'));
        $this->get("/accounting/withholding-types/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/WithholdingTypes/Create')->where('bpTypes', TaxBuktiPotong::TYPES));
        $this->get('/accounting/withholding-types/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/withholding-types', [
            'company_id' => $companyId, 'code' => 'PPH23', 'bp_type' => 'BP23', 'name' => 'PPh 23',
            'rate' => 2, 'gl_payable_account_id' => $accountId,
        ])->assertRedirect(route('accounting.withholding-types.index', ['company_id' => $companyId]));

        $withholdingId = null;
        $tenant->run(function () use (&$withholdingId, $companyId) {
            $withholdingId = WithholdingType::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/withholding-types/{$withholdingId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/WithholdingTypes/Edit'));

        $this->put("/accounting/withholding-types/{$withholdingId}", [
            'code' => 'PPH23', 'bp_type' => 'BP23', 'name' => 'PPh 23 (renamed)', 'rate' => 2, 'gl_payable_account_id' => $accountId, 'is_active' => true,
        ])->assertRedirect(route('accounting.withholding-types.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($withholdingId) {
            $this->assertSame('PPh 23 (renamed)', WithholdingType::query()->find($withholdingId)->name);
        });

        $this->delete("/accounting/withholding-types/{$withholdingId}")->assertRedirect(route('accounting.withholding-types.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($withholdingId) {
            $this->assertNull(WithholdingType::query()->find($withholdingId));
        });
    }

    public function test_withholding_type_store_and_update_reject_invalid_references_and_duplicate_codes(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId, $existingId, $otherId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$accountId, &$existingId, &$otherId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $existingId = $this->makeWithholdingType($company, ['code' => 'PPH23', 'gl_payable_account_id' => $accountId])->id;
            $otherId = $this->makeWithholdingType($company, ['code' => 'PPH24', 'gl_payable_account_id' => $accountId])->id;
        });

        $this->post('/accounting/withholding-types', [
            'company_id' => 999999, 'code' => 'X', 'name' => 'X', 'rate' => 2, 'gl_payable_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'gl_payable_account_id']);

        $this->post('/accounting/withholding-types', [
            'company_id' => $companyId, 'code' => 'PPH23', 'name' => 'Dup', 'rate' => 2, 'gl_payable_account_id' => $accountId,
        ])->assertSessionHasErrors(['code']);

        $this->put("/accounting/withholding-types/{$existingId}", [
            'code' => 'PPH23', 'name' => 'X', 'rate' => 2, 'gl_payable_account_id' => 999999,
        ])->assertSessionHasErrors(['gl_payable_account_id']);

        // Renaming $otherId to $existingId's own code is a duplicate on UPDATE, not just on create.
        $this->put("/accounting/withholding-types/{$otherId}", [
            'code' => 'PPH23', 'name' => 'X', 'rate' => 2, 'gl_payable_account_id' => $accountId,
        ])->assertSessionHasErrors(['code']);
    }
}
