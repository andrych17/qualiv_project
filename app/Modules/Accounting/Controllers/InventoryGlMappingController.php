<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\InventoryGlMapping;
use App\Modules\Accounting\Requests\StoreInventoryGlMappingRequest;
use App\Modules\Accounting\Requests\UpdateInventoryGlMappingRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\InventoryGlMappingService;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3H — item/category → GL account mapping CRUD. */
class InventoryGlMappingController extends Controller
{
    public function __construct(private readonly InventoryGlMappingService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $items = InventoryItem::query()->orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');
        $categories = InventoryCategory::query()->orderBy('name')->get(['id', 'code', 'name'])->keyBy('id');

        $mappings = InventoryGlMapping::query()
            ->where('company_id', $companyId)
            ->with(['inventoryAssetAccount:id,account_code,account_name', 'cogsAccount:id,account_code,account_name', 'grniAccount:id,account_code,account_name', 'adjustmentAccount:id,account_code,account_name'])
            ->get();

        return Inertia::render('Accounting/InventoryGlMappings/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'mappings' => $mappings->map(function (InventoryGlMapping $m) use ($items, $categories) {
                $scopeLabel = $m->inventory_item_id
                    ? 'Item: '.($items->get($m->inventory_item_id)?->code ?? '#'.$m->inventory_item_id).' — '.($items->get($m->inventory_item_id)?->name ?? 'Unknown item')
                    : 'Category: '.($categories->get($m->inventory_category_id)?->name ?? 'Unknown category');

                return [
                    'id' => $m->id,
                    'scope_label' => $scopeLabel,
                    'inventory_asset_account' => "{$m->inventoryAssetAccount->account_code} — {$m->inventoryAssetAccount->account_name}",
                    'cogs_account' => $m->cogsAccount ? "{$m->cogsAccount->account_code} — {$m->cogsAccount->account_name}" : null,
                    'grni_account' => $m->grniAccount ? "{$m->grniAccount->account_code} — {$m->grniAccount->account_name}" : null,
                    'adjustment_account' => $m->adjustmentAccount ? "{$m->adjustmentAccount->account_code} — {$m->adjustmentAccount->account_name}" : null,
                ];
            }),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/InventoryGlMappings/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreInventoryGlMappingRequest $request)
    {
        $mapping = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('accounting.inventory-gl-mappings.index', ['company_id' => $mapping->company_id])->with('success', 'Mapping saved.');
    }

    public function edit(InventoryGlMapping $mapping): Response
    {
        return Inertia::render('Accounting/InventoryGlMappings/Edit', [
            'mapping' => $mapping->only(['id', 'company_id', 'inventory_item_id', 'inventory_category_id', 'inventory_asset_account_id', 'cogs_account_id', 'grni_account_id', 'adjustment_account_id']),
            ...$this->formOptions($mapping->company_id),
        ]);
    }

    public function update(UpdateInventoryGlMappingRequest $request, InventoryGlMapping $mapping)
    {
        $this->service->update($mapping, $request->validated(), $request->user()->id);

        return redirect()->route('accounting.inventory-gl-mappings.index', ['company_id' => $mapping->company_id])->with('success', 'Mapping updated.');
    }

    public function destroy(Request $request, InventoryGlMapping $mapping)
    {
        $companyId = $mapping->company_id;
        $this->service->delete($mapping, $request->user()->id);

        return redirect()->route('accounting.inventory-gl-mappings.index', ['company_id' => $companyId])->with('success', 'Mapping deleted.');
    }

    private function formOptions(?int $companyId): array
    {
        return [
            'items' => InventoryItem::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (InventoryItem $i) => ['value' => $i->id, 'label' => "{$i->code} — {$i->name}"]),
            'categories' => InventoryCategory::query()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (InventoryCategory $c) => ['value' => $c->id, 'label' => $c->name]),
            'accounts' => $companyId
                ? Account::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('account_code')
                    ->get(['id', 'account_code', 'account_name'])
                    ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"])
                : [],
        ];
    }
}
