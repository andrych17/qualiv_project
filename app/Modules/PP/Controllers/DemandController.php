<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Requests\StoreManualDemandRequest;
use App\Modules\PP\Requests\UpdateManualDemandRequest;
use App\Modules\PP\Services\DemandAggregationService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3B Demand Aggregation (read model over every source) + manual entry (Entry). */
class DemandController extends Controller
{
    private const SORTABLE = ['need_by_date', 'qty', 'created_at'];

    public function __construct(protected DemandAggregationService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'product_id', 'source_type', 'sort', 'direction', 'per_page');

        $lines = DemandLine::query()
            ->baseline()
            ->with(['product:id,sku,name', 'header:id,source_type,subject_type,subject_id,note'])
            ->filter($filters)
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->whereHas('product', function ($query) use ($search) {
                    $query->where('sku', 'ilike', '%'.$search.'%')
                        ->orWhere('name', 'ilike', '%'.$search.'%');
                });
            })
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'need_by_date'),
                fn ($query) => $query->orderBy('need_by_date'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (DemandLine $l) => [
                'id' => $l->id,
                'demand_hdr_id' => $l->demand_hdr_id,
                'product_sku' => $l->product?->sku,
                'product_name' => $l->product?->name,
                'source_type' => $l->header?->source_type,
                'note' => $l->header?->note,
                'need_by_date' => $l->need_by_date->toDateString(),
                'qty' => (float) $l->qty,
            ]);

        return Inertia::render('PP/Demand/Index', [
            'lines' => $lines,
            'filters' => $filters,
            'sourceTypes' => [
                DemandHeader::SOURCE_MANUAL,
                DemandHeader::SOURCE_FORECAST,
                DemandHeader::SOURCE_SALES_ORDER,
                DemandHeader::SOURCE_SAFETY_STOCK,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/Demand/Create');
    }

    public function store(StoreManualDemandRequest $request)
    {
        $this->service->createManual($request->validated());

        return redirect()->route('pp.demand.index')->with('success', 'Manual demand added.');
    }

    public function edit(DemandHeader $demand): Response
    {
        $this->assertManual($demand);

        return Inertia::render('PP/Demand/Edit', [
            'demand' => [
                'id' => $demand->id,
                'demand_date' => $demand->demand_date->toDateString(),
                'note' => $demand->note,
                'lines' => $demand->lines->map(fn (DemandLine $l) => [
                    'product_id' => $l->product_id,
                    'need_by_date' => $l->need_by_date->toDateString(),
                    'qty' => (float) $l->qty,
                ]),
            ],
        ]);
    }

    public function update(UpdateManualDemandRequest $request, DemandHeader $demand)
    {
        $this->assertManual($demand);

        $this->service->updateManual($demand, $request->validated());

        return redirect()->route('pp.demand.index')->with('success', 'Manual demand updated.');
    }

    public function destroy(DemandHeader $demand)
    {
        $this->assertManual($demand);

        $this->service->deleteManual($demand);

        return redirect()->route('pp.demand.index')->with('success', 'Manual demand deleted.');
    }

    /** §3B safety stock / reorder points source — recomputed on demand, not on a schedule (no inventory-change event to hook yet). */
    public function recalculateSafetyStock()
    {
        $changed = $this->service->recalculateSafetyStockDemand();

        return redirect()->route('pp.demand.index')->with('success', "Safety stock demand recalculated ({$changed} item(s) updated).");
    }

    private function assertManual(DemandHeader $demand): void
    {
        if ($demand->source_type !== DemandHeader::SOURCE_MANUAL) {
            throw ValidationException::withMessages(['demand' => 'Only manually entered demand can be edited or deleted here — this row is system-generated.']);
        }
    }
}
