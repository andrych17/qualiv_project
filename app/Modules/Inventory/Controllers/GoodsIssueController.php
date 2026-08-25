<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreGoodsIssueRequest;
use App\Modules\Inventory\Requests\UpdateGoodsIssueRequest;
use App\Modules\Inventory\Services\GoodsIssueService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3E Goods Issue (Entry / Engine). */
class GoodsIssueController extends Controller
{
    private const SORTABLE = ['issue_date', 'created_at'];

    public function __construct(protected GoodsIssueService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'warehouse_id', 'sort', 'direction', 'per_page');

        $issues = GoodsIssue::query()
            ->with('warehouse:id,name')
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (GoodsIssue $i) => [
                'id' => $i->id,
                'reason' => $i->reason,
                'warehouse_name' => $i->warehouse?->name,
                'issue_date_formatted' => $i->issue_date?->format('d M Y'),
                'line_count' => $i->lines_count,
                'status' => $i->status,
            ]);

        return Inertia::render('Inventory/GoodsIssues/Index', [
            'issues' => $issues,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/GoodsIssues/Create', $this->formProps());
    }

    public function store(StoreGoodsIssueRequest $request)
    {
        $issue = $this->service->create($request->validated());

        return redirect()->route('inventory.goodsIssues.edit', $issue)->with('success', 'Issue saved as draft.');
    }

    public function edit(GoodsIssue $goodsIssue): Response
    {
        return Inertia::render('Inventory/GoodsIssues/Edit', [
            ...$this->formProps(),
            'issue' => $this->toFormData($goodsIssue),
        ]);
    }

    public function update(UpdateGoodsIssueRequest $request, GoodsIssue $goodsIssue)
    {
        $this->service->update($goodsIssue, $request->validated());

        return redirect()->route('inventory.goodsIssues.edit', $goodsIssue)->with('success', 'Issue updated.');
    }

    public function destroy(GoodsIssue $goodsIssue)
    {
        $this->service->delete($goodsIssue);

        return redirect()->route('inventory.goodsIssues.index')->with('success', 'Issue deleted.');
    }

    public function post(GoodsIssue $goodsIssue)
    {
        $this->service->post($goodsIssue);

        return redirect()->route('inventory.goodsIssues.edit', $goodsIssue)->with('success', 'Issue posted — stock has been deducted.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'uoms' => Uom::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'productTracking' => Product::query()->where('is_active', true)->pluck('tracking_mode', 'id'),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(GoodsIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'warehouse_id' => $issue->warehouse_id,
            'issue_date' => $issue->issue_date->toDateString(),
            'subject_type' => $issue->subject_type,
            'subject_id' => $issue->subject_id,
            'reason' => $issue->reason,
            'status' => $issue->status,
            'lines' => $issue->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'qty' => (float) $l->qty,
                'uom_id' => $l->uom_id,
                'source_location_id' => $l->source_location_id,
                'batch_id' => $l->batch_id,
                'batch_label' => $l->batch?->batch_number,
                'expiry_override_reason' => $l->expiry_override_reason,
                'serial_numbers' => $l->serial_numbers ?? [],
            ]),
        ];
    }
}
