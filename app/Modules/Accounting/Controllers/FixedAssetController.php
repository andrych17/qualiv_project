<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Requests\StoreFixedAssetRequest;
use App\Modules\Accounting\Requests\UpdateFixedAssetRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\FixedAssetService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3G — the asset register. show() is the detail page: both depreciation schedules side by side, plus the dispose action while active. */
class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $assets = FixedAsset::query()->where('company_id', $companyId)->with('assetGroup:id,name')->orderBy('asset_no')->get();

        return Inertia::render('Accounting/FixedAssets/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'assets' => $assets->map(fn (FixedAsset $a) => [
                'id' => $a->id,
                'asset_no' => $a->asset_no,
                'name' => $a->name,
                'asset_group_name' => $a->assetGroup->name,
                'acquisition_date' => $a->acquisition_date->toDateString(),
                'acquisition_cost' => (float) $a->acquisition_cost,
                'status' => $a->status,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/FixedAssets/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'assetGroups' => $this->groupOptions($companyId),
            'accounts' => $this->accountOptions($companyId),
        ]);
    }

    public function store(StoreFixedAssetRequest $request)
    {
        $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('accounting.fixed-assets.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Asset created.');
    }

    public function edit(FixedAsset $asset): Response
    {
        return Inertia::render('Accounting/FixedAssets/Edit', [
            'asset' => $asset->only([
                'id', 'company_id', 'asset_group_id', 'asset_no', 'name', 'vendor_partner_id',
                'acquisition_date', 'acquisition_cost', 'asset_gl_account_id', 'accumulated_depreciation_gl_account_id',
                'depreciation_expense_gl_account_id', 'commercial_useful_life_months', 'commercial_method',
                'commercial_declining_rate', 'fiscal_method', 'status',
            ]),
            'assetGroups' => $this->groupOptions($asset->company_id),
            'accounts' => $this->accountOptions($asset->company_id),
        ]);
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $asset)
    {
        $this->service->update($asset, $request->validated());

        return redirect()->route('accounting.fixed-assets.index', ['company_id' => $asset->company_id])
            ->with('success', 'Asset updated.');
    }

    public function destroy(FixedAsset $asset)
    {
        $companyId = $asset->company_id;
        $this->service->delete($asset);

        return redirect()->route('accounting.fixed-assets.index', ['company_id' => $companyId])
            ->with('success', 'Asset deleted.');
    }

    public function show(FixedAsset $asset): Response
    {
        $asset->load([
            'assetGroup', 'vendor:id,name', 'assetGlAccount:id,account_code,account_name',
            'accumulatedDepreciationGlAccount:id,account_code,account_name', 'depreciationExpenseGlAccount:id,account_code,account_name',
            'commercialSchedule.fiscalPeriod:id,period_no,start_date,end_date', 'fiscalSchedule.fiscalPeriod:id,period_no,start_date,end_date',
            'disposal',
        ]);

        return Inertia::render('Accounting/FixedAssets/Show', [
            'asset' => [
                'id' => $asset->id,
                'company_id' => $asset->company_id,
                'asset_no' => $asset->asset_no,
                'name' => $asset->name,
                'status' => $asset->status,
                'asset_group_name' => $asset->assetGroup->name,
                'vendor_name' => $asset->vendor?->name,
                'acquisition_date' => $asset->acquisition_date->toDateString(),
                'acquisition_cost' => (float) $asset->acquisition_cost,
                'asset_gl_account_label' => "{$asset->assetGlAccount->account_code} {$asset->assetGlAccount->account_name}",
                'accumulated_depreciation_gl_account_label' => "{$asset->accumulatedDepreciationGlAccount->account_code} {$asset->accumulatedDepreciationGlAccount->account_name}",
                'depreciation_expense_gl_account_label' => "{$asset->depreciationExpenseGlAccount->account_code} {$asset->depreciationExpenseGlAccount->account_name}",
                'commercial_method' => $asset->commercial_method,
                'commercial_useful_life_months' => $asset->commercial_useful_life_months,
                'commercial_declining_rate' => $asset->commercial_declining_rate !== null ? (float) $asset->commercial_declining_rate : null,
                'fiscal_method' => $asset->fiscal_method,
            ],
            'commercialSchedule' => $asset->commercialSchedule->map(fn ($r) => [
                'period_no' => $r->fiscalPeriod->period_no, 'period_end' => $r->fiscalPeriod->end_date->toDateString(),
                'depreciation_amount' => (float) $r->depreciation_amount, 'accumulated_depreciation' => (float) $r->accumulated_depreciation, 'net_book_value' => (float) $r->net_book_value,
                'journal_id' => $r->journal_id,
            ]),
            'fiscalSchedule' => $asset->fiscalSchedule->map(fn ($r) => [
                'period_no' => $r->fiscalPeriod->period_no, 'period_end' => $r->fiscalPeriod->end_date->toDateString(),
                'depreciation_amount' => (float) $r->depreciation_amount, 'accumulated_depreciation' => (float) $r->accumulated_depreciation, 'net_book_value' => (float) $r->net_book_value,
            ]),
            'disposal' => $asset->disposal ? [
                'disposal_date' => $asset->disposal->disposal_date->toDateString(),
                'proceeds' => (float) $asset->disposal->proceeds,
                'commercial_nbv_at_disposal' => (float) $asset->disposal->commercial_nbv_at_disposal,
                'gain_loss_amount' => (float) $asset->disposal->gain_loss_amount,
                'journal_id' => $asset->disposal->journal_id,
            ] : null,
        ]);
    }

    private function groupOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return AssetGroup::query()
            ->where('company_id', $companyId)->where('is_active', true)->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (AssetGroup $g) => ['value' => $g->id, 'label' => "{$g->code} — {$g->name}"])
            ->values()->all();
    }

    private function accountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Account::query()
            ->where('company_id', $companyId)->where('is_active', true)->where('is_control_account', false)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} {$a->account_name}"])
            ->values()->all();
    }
}
