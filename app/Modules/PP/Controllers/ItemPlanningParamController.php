<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Requests\StoreItemPlanningParamRequest;
use App\Modules\PP\Requests\UpdateItemPlanningParamRequest;
use App\Modules\PP\Services\ItemPlanningParamService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3A Item Planning Parameters (Entry) — one row per INVENTORY.products item. */
class ItemPlanningParamController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['safety_stock_qty', 'lead_time_days', 'created_at'];

    public function __construct(
        protected ItemPlanningParamService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'make_type', 'sort', 'direction', 'per_page');

        $params = ItemPlanningParam::query()
            ->with('product:id,sku,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ItemPlanningParam $p) => [
                'id' => $p->id,
                'product_sku' => $p->product?->sku,
                'product_name' => $p->product?->name,
                'make_type' => $p->make_type,
                'safety_stock_qty' => (float) $p->safety_stock_qty,
                'lead_time_days' => $p->lead_time_days,
                'planning_fence_days' => $p->planning_fence_days,
            ]);

        return Inertia::render('PP/ItemPlanningParams/Index', [
            'params' => $params,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/ItemPlanningParams/Create', [
            'customFields' => $this->customFields->formPayload(ItemPlanningParamService::ENTITY),
        ]);
    }

    public function store(StoreItemPlanningParamRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.itemPlanningParams.index')->with('success', 'Item planning parameters created.');
    }

    public function edit(ItemPlanningParam $itemPlanningParam): Response
    {
        return Inertia::render('PP/ItemPlanningParams/Edit', [
            'param' => $this->toFormData($itemPlanningParam),
            'customFields' => $this->customFields->formPayload(ItemPlanningParamService::ENTITY, $itemPlanningParam->id),
        ]);
    }

    public function update(UpdateItemPlanningParamRequest $request, ItemPlanningParam $itemPlanningParam)
    {
        $this->service->update($itemPlanningParam, $request->validated());

        return redirect()->route('pp.itemPlanningParams.index')->with('success', 'Item planning parameters updated.');
    }

    public function destroy(ItemPlanningParam $itemPlanningParam)
    {
        $this->service->delete($itemPlanningParam);

        return redirect()->route('pp.itemPlanningParams.index')->with('success', 'Item planning parameters deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ItemPlanningParam::class, fn (ItemPlanningParam $p) => $this->service->delete($p));
    }

    /** @return array<string, mixed> */
    private function toFormData(ItemPlanningParam $param): array
    {
        return [
            'id' => $param->id,
            'product_id' => $param->product_id,
            'product_label' => $param->product ? "{$param->product->sku} — {$param->product->name}" : null,
            'make_type' => $param->make_type,
            'min_lot_qty' => $param->min_lot_qty !== null ? (float) $param->min_lot_qty : null,
            'max_lot_qty' => $param->max_lot_qty !== null ? (float) $param->max_lot_qty : null,
            'fixed_lot_qty' => $param->fixed_lot_qty !== null ? (float) $param->fixed_lot_qty : null,
            'economic_lot_qty' => $param->economic_lot_qty !== null ? (float) $param->economic_lot_qty : null,
            'safety_stock_qty' => (float) $param->safety_stock_qty,
            'lead_time_days' => $param->lead_time_days,
            'planning_lead_time_days' => $param->planning_lead_time_days,
            'order_multiple' => $param->order_multiple !== null ? (float) $param->order_multiple : null,
            'scrap_pct' => (float) $param->scrap_pct,
            'yield_pct_override' => $param->yield_pct_override !== null ? (float) $param->yield_pct_override : null,
            'production_calendar_ref' => $param->production_calendar_ref,
            'preferred_line_ref_id' => $param->preferred_line_ref_id,
            'alternate_line_ref_id' => $param->alternate_line_ref_id,
            'planning_fence_days' => $param->planning_fence_days,
        ];
    }
}
