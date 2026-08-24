<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreAssetGroupRequest;
use App\Modules\Accounting\Requests\UpdateAssetGroupRequest;
use App\Modules\Accounting\Services\AssetGroupService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3G — Indonesian fiscal tax classification, tenant-editable (see AssetGroupService docblock). */
class AssetGroupController extends Controller
{
    public function __construct(private readonly AssetGroupService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        return Inertia::render('Accounting/AssetGroups/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'assetGroups' => AssetGroup::query()->where('company_id', $companyId)->orderBy('code')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/AssetGroups/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $request->integer('company_id') ?: null,
        ]);
    }

    public function store(StoreAssetGroupRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.asset-groups.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Asset group created.');
    }

    public function edit(AssetGroup $assetGroup): Response
    {
        return Inertia::render('Accounting/AssetGroups/Edit', [
            'assetGroup' => $assetGroup,
        ]);
    }

    public function update(UpdateAssetGroupRequest $request, AssetGroup $assetGroup)
    {
        $this->service->update($assetGroup, $request->validated());

        return redirect()->route('accounting.asset-groups.index', ['company_id' => $assetGroup->company_id])
            ->with('success', 'Asset group updated.');
    }

    public function destroy(AssetGroup $assetGroup)
    {
        $companyId = $assetGroup->company_id;
        $this->service->delete($assetGroup);

        return redirect()->route('accounting.asset-groups.index', ['company_id' => $companyId])
            ->with('success', 'Asset group deleted.');
    }

    public function seedStarter(Request $request, Company $company)
    {
        $this->service->seedStarterGroups($company);

        return redirect()->route('accounting.asset-groups.index', ['company_id' => $company->id])
            ->with('success', 'Starter asset groups seeded.');
    }
}
