<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\CostCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3K Multi Company (minimal master) and §3B/§3I Cost Centers — the two dimensions every other Accounting engine scopes by. */
class CompanyCostCenterTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_create_and_update_a_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->get('/accounting/companies')->assertOk()->assertInertia(fn ($page) => $page->component('Accounting/Companies/Index'));
        $this->get('/accounting/companies/create')->assertOk()->assertInertia(fn ($page) => $page->component('Accounting/Companies/Create'));

        $this->post('/accounting/companies', [
            'legal_name' => 'PT Nusaevo Legal',
            'base_currency' => 'IDR',
            'fiscal_year_start_month' => 1,
        ])->assertRedirect(route('accounting.companies.index'));

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = Company::query()->where('legal_name', 'PT Nusaevo Legal')->value('id');
        });

        $this->get("/accounting/companies/{$companyId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Companies/Edit')->where('company.legal_name', 'PT Nusaevo Legal'));

        $this->put("/accounting/companies/{$companyId}", [
            'legal_name' => 'PT Nusaevo Legal (renamed)',
            'base_currency' => 'IDR',
            'fiscal_year_start_month' => 1,
            'is_active' => true,
        ])->assertRedirect(route('accounting.companies.index'));

        $tenant->run(function () use ($companyId) {
            $this->assertSame('PT Nusaevo Legal (renamed)', Company::query()->find($companyId)->legal_name);
        });
    }

    public function test_update_rejects_invalid_control_account_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->put("/accounting/companies/{$companyId}", [
            'legal_name' => 'Bad Refs',
            'base_currency' => 'IDR',
            'fiscal_year_start_month' => 1,
            'is_active' => true,
            'ar_control_account_id' => 999999,
            'ap_control_account_id' => 999999,
            'inventory_control_account_id' => 999999,
            'payroll_net_pay_payable_account_id' => 999999,
        ])->assertSessionHasErrors([
            'ar_control_account_id', 'ap_control_account_id', 'inventory_control_account_id', 'payroll_net_pay_payable_account_id',
        ]);
    }

    public function test_company_edit_lists_only_its_own_control_accounts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeAccount($company, ['is_control_account' => true, 'account_code' => '11000']);
            $this->makeAccount($company, ['is_control_account' => false, 'account_code' => '61000']);
        });

        $this->get("/accounting/companies/{$companyId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->has('controlAccounts', 1));
    }

    /** CompanyContextService is shared by nearly every Accounting screen — direct coverage of all three resolution branches. */
    public function test_company_context_service_resolution_order(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $service = app(CompanyContextService::class);
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);
            $companyB = $this->makeCompany(['legal_name' => 'Company B']);
            $companies = Company::query()->orderBy('legal_name')->get(['id', 'legal_name']);

            // No explicit param, no session yet -> first company.
            $request1 = Request::create('/accounting/accounts');
            $request1->setLaravelSession(app('session.store'));
            $this->assertSame($companyA->id, $service->resolve($request1, $companies));

            // Explicit ?company_id= wins and persists to session.
            $request2 = Request::create('/accounting/accounts', 'GET', ['company_id' => $companyB->id]);
            $request2->setLaravelSession(app('session.store'));
            $this->assertSame($companyB->id, $service->resolve($request2, $companies));

            // A later request with no explicit param reuses the session value.
            $request3 = Request::create('/accounting/accounts');
            $request3->setLaravelSession(app('session.store'));
            $this->assertSame($companyB->id, $service->resolve($request3, $companies));

            // An explicit param for a company NOT in the caller's scoped list is ignored,
            // falling through to the still-valid session value.
            $request4 = Request::create('/accounting/accounts', 'GET', ['company_id' => 999999]);
            $request4->setLaravelSession(app('session.store'));
            $this->assertSame($companyB->id, $service->resolve($request4, $companies));
        });
    }

    public function test_company_context_falls_back_to_first_when_session_company_no_longer_scoped(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $service = app(CompanyContextService::class);
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);

            $request = Request::create('/accounting/accounts', 'GET', ['company_id' => $companyA->id]);
            $request->setLaravelSession(app('session.store'));
            $service->resolve($request, Company::query()->get(['id', 'legal_name']));

            // A later caller whose own scoped list no longer contains that session company
            // (e.g. it was deactivated) falls through to its own list's first entry.
            $companyB = $this->makeCompany(['legal_name' => 'Company B']);
            $onlyB = collect([$companyB]);
            $request2 = Request::create('/accounting/accounts');
            $request2->setLaravelSession(app('session.store'));
            $this->assertSame($companyB->id, $service->resolve($request2, $onlyB));
        });
    }

    public function test_context_for_returns_companies_and_current_id(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $this->makeCompany();
            $service = app(CompanyContextService::class);
            $request = Request::create('/accounting/accounts');
            $request->setLaravelSession(app('session.store'));

            $context = $service->contextFor($request);
            $this->assertArrayHasKey('companies', $context);
            $this->assertArrayHasKey('currentCompanyId', $context);
            $this->assertNotEmpty($context['companies']);
        });
    }

    public function test_admin_can_crud_a_cost_center_with_hierarchy(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/cost-centers?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/CostCenters/Index'));
        $this->get("/accounting/cost-centers/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/CostCenters/Create'));
        // No company_id query param — CostCenterController::parentOptions()'s early-return branch.
        $this->get('/accounting/cost-centers/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('parents', []));

        $this->post('/accounting/cost-centers', ['company_id' => $companyId, 'code' => 'OPS', 'name' => 'Operations'])
            ->assertRedirect(route('accounting.cost-centers.index', ['company_id' => $companyId]));

        $parentId = null;
        $tenant->run(function () use (&$parentId, $companyId) {
            $parentId = CostCenter::query()->where('company_id', $companyId)->where('code', 'OPS')->value('id');
        });

        $this->post('/accounting/cost-centers', ['company_id' => $companyId, 'code' => 'OPS-A', 'name' => 'Ops Sub A', 'parent_cost_center_id' => $parentId])
            ->assertRedirect();

        $childId = null;
        $tenant->run(function () use (&$childId) {
            $childId = CostCenter::query()->where('code', 'OPS-A')->value('id');
        });

        // Re-list with a real parent/child pair present — exercises indent()'s recursive
        // depth>0 walk (the earlier index() call above had zero cost centers).
        $this->get("/accounting/cost-centers?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('costCenters', 2)
                ->where('costCenters.0.depth', 0)
                ->where('costCenters.1.depth', 1));

        $this->get("/accounting/cost-centers/{$childId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('costCenter.name', 'Ops Sub A'));

        $this->put("/accounting/cost-centers/{$childId}", ['company_id' => $companyId, 'code' => 'OPS-A', 'name' => 'Ops Sub A (renamed)'])
            ->assertRedirect();

        $tenant->run(function () use ($childId) {
            $this->assertSame('Ops Sub A (renamed)', CostCenter::query()->find($childId)->name);
        });

        $this->delete("/accounting/cost-centers/{$childId}")->assertRedirect();
        $tenant->run(function () use ($childId) {
            $this->assertNull(CostCenter::query()->find($childId));
        });
    }

    public function test_cost_center_store_rejects_invalid_company_parent_and_duplicate_code(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
            $this->makeCostCenter(Company::find($companyId), ['code' => 'DUP']);
        });

        $this->post('/accounting/cost-centers', ['company_id' => 999999, 'code' => 'X', 'name' => 'X', 'parent_cost_center_id' => 999999])
            ->assertSessionHasErrors(['company_id', 'parent_cost_center_id']);

        $this->post('/accounting/cost-centers', ['company_id' => $companyId, 'code' => 'DUP', 'name' => 'Duplicate'])
            ->assertSessionHasErrors(['code']);
    }

    public function test_cost_center_update_rejects_self_as_parent(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $costCenterId] = [null, null];
        $tenant->run(function () use (&$companyId, &$costCenterId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $costCenterId = $this->makeCostCenter($company)->id;
        });

        $this->put("/accounting/cost-centers/{$costCenterId}", [
            'company_id' => $companyId, 'code' => 'SELF', 'name' => 'Self', 'parent_cost_center_id' => $costCenterId,
        ])->assertSessionHasErrors(['parent_cost_center_id']);
    }

    public function test_cost_center_update_rejects_an_invalid_parent_and_a_duplicate_code(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$costCenterId, $otherCode] = [null, null];
        $tenant->run(function () use (&$costCenterId, &$otherCode) {
            $company = $this->makeCompany();
            $costCenterId = $this->makeCostCenter($company, ['code' => 'CC-A'])->id;
            $otherCode = $this->makeCostCenter($company, ['code' => 'CC-B'])->code;
        });

        $this->put("/accounting/cost-centers/{$costCenterId}", [
            'code' => 'CC-A', 'name' => 'Bad Parent', 'parent_cost_center_id' => 999999,
        ])->assertSessionHasErrors(['parent_cost_center_id']);

        $this->put("/accounting/cost-centers/{$costCenterId}", [
            'code' => $otherCode, 'name' => 'Dupe Code',
        ])->assertSessionHasErrors(['code']);
    }

    /**
     * UpdateCostCenterRequest already rejects self-as-parent before the service layer ever
     * sees it (see test_cost_center_update_rejects_self_as_parent), so CostCenterService's own
     * redundant self-parent guard needs a direct call to reach.
     */
    public function test_service_layer_rejects_self_as_parent(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $costCenter = $this->makeCostCenter($company);

            $this->expectException(ValidationException::class);
            app(CostCenterService::class)->update($costCenter, [
                'code' => $costCenter->code, 'name' => $costCenter->name, 'parent_cost_center_id' => $costCenter->id,
            ]);
        });
    }

    public function test_cost_center_delete_is_blocked_when_it_has_children(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $parentId = null;
        $tenant->run(function () use (&$parentId) {
            $company = $this->makeCompany();
            $parent = $this->makeCostCenter($company);
            $parentId = $parent->id;
            $this->makeCostCenter($company, ['parent_cost_center_id' => $parent->id]);
        });

        $this->delete("/accounting/cost-centers/{$parentId}")->assertSessionHasErrors(['cost_center']);
    }

    public function test_service_layer_rejects_parent_from_a_different_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $service = app(CostCenterService::class);
            $companyA = $this->makeCompany(['legal_name' => 'A']);
            $companyB = $this->makeCompany(['legal_name' => 'B']);
            $parentInA = $this->makeCostCenter($companyA);

            $this->expectException(ValidationException::class);
            $service->create(['company_id' => $companyB->id, 'code' => 'X', 'name' => 'X', 'parent_cost_center_id' => $parentInA->id]);
        });
    }
}
