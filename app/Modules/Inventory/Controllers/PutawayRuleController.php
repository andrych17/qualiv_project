<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\PutawayRule;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StorePutawayRuleRequest;
use App\Modules\Inventory\Requests\UpdatePutawayRuleRequest;
use App\Modules\Inventory\Services\PutawayRuleService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3R Put-away Rules (Entry) — tenant-editable lookup; see PutawayRuleService::resolve() for the Goods Receipt default it drives. */
class PutawayRuleController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['priority_order', 'created_at'];

    public function __construct(protected PutawayRuleService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('warehouse_id', 'status', 'sort', 'direction', 'per_page');

        $rules = PutawayRule::query()
            ->with(['warehouse:id,name', 'product:id,sku,name', 'category:id,name', 'targetLocation:id,code'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'priority_order'),
                fn ($query) => $query->orderBy('priority_order'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (PutawayRule $r) => [
                'id' => $r->id,
                'warehouse_name' => $r->warehouse?->name,
                'condition' => $r->product ? "Product {$r->product->sku}" : "Category {$r->category?->name}",
                'target_location_code' => $r->targetLocation?->code,
                'priority_order' => $r->priority_order,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('Inventory/PutawayRules/Index', [
            'rules' => $rules,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/PutawayRules/Create', $this->formProps());
    }

    public function store(StorePutawayRuleRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.putawayRules.index')->with('success', 'Put-away rule created.');
    }

    public function edit(PutawayRule $putawayRule): Response
    {
        return Inertia::render('Inventory/PutawayRules/Edit', [
            ...$this->formProps(),
            'rule' => $this->toFormData($putawayRule),
        ]);
    }

    public function update(UpdatePutawayRuleRequest $request, PutawayRule $putawayRule)
    {
        $this->service->update($putawayRule, $request->validated());

        return redirect()->route('inventory.putawayRules.index')->with('success', 'Put-away rule updated.');
    }

    public function destroy(PutawayRule $putawayRule)
    {
        $this->service->delete($putawayRule);

        return redirect()->route('inventory.putawayRules.index')->with('success', 'Put-away rule deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, PutawayRule::class, fn (PutawayRule $r) => $this->service->delete($r));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(PutawayRule $rule): array
    {
        return [
            'id' => $rule->id,
            'warehouse_id' => $rule->warehouse_id,
            'product_id' => $rule->product_id,
            'category_id' => $rule->category_id,
            'target_location_id' => $rule->target_location_id,
            'priority_order' => $rule->priority_order,
            'is_active' => $rule->is_active,
        ];
    }
}
