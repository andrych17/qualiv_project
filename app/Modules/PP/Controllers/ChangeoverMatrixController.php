<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\ChangeoverMatrix;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Requests\StoreChangeoverMatrixRequest;
use App\Modules\PP\Requests\UpdateChangeoverMatrixRequest;
use App\Modules\PP\Services\ChangeoverMatrixService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3J Setup & Changeover Matrix — from/to product-or-family switching cost, consumed by §3I's minimize_setup/minimize_changeover strategies. */
class ChangeoverMatrixController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['changeover_minutes', 'cleaning_minutes', 'created_at'];

    public function __construct(protected ChangeoverMatrixService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('resource_group_id', 'status', 'sort', 'direction', 'per_page');

        $rows = ChangeoverMatrix::query()
            ->with(['fromProduct:id,sku,name', 'toProduct:id,sku,name', 'resourceGroup:id,code,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ChangeoverMatrix $row) => $this->toRow($row));

        return Inertia::render('PP/ChangeoverMatrix/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'resourceGroupOptions' => $this->resourceGroupOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/ChangeoverMatrix/Create', [
            'resourceGroupOptions' => $this->resourceGroupOptions(),
        ]);
    }

    public function store(StoreChangeoverMatrixRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.changeoverMatrix.index')->with('success', 'Changeover matrix row created.');
    }

    public function edit(ChangeoverMatrix $changeover_matrix): Response
    {
        return Inertia::render('PP/ChangeoverMatrix/Edit', [
            'row' => $this->toFormData($changeover_matrix),
            'resourceGroupOptions' => $this->resourceGroupOptions(),
        ]);
    }

    public function update(UpdateChangeoverMatrixRequest $request, ChangeoverMatrix $changeover_matrix)
    {
        $this->service->update($changeover_matrix, $request->validated());

        return redirect()->route('pp.changeoverMatrix.index')->with('success', 'Changeover matrix row updated.');
    }

    public function destroy(ChangeoverMatrix $changeover_matrix)
    {
        $this->service->delete($changeover_matrix);

        return redirect()->route('pp.changeoverMatrix.index')->with('success', 'Changeover matrix row deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ChangeoverMatrix::class, fn (ChangeoverMatrix $row) => $this->service->delete($row));
    }

    /** @return array<string, mixed> */
    private function toRow(ChangeoverMatrix $row): array
    {
        return [
            'id' => $row->id,
            'from_label' => $row->fromProduct ? "{$row->fromProduct->sku} — {$row->fromProduct->name}" : "family: {$row->from_family}",
            'to_label' => $row->toProduct ? "{$row->toProduct->sku} — {$row->toProduct->name}" : "family: {$row->to_family}",
            'resource_group_label' => $row->resourceGroup ? "{$row->resourceGroup->code} — {$row->resourceGroup->name}" : 'All groups',
            'changeover_minutes' => $row->changeover_minutes,
            'cleaning_minutes' => $row->cleaning_minutes,
            'is_active' => $row->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(ChangeoverMatrix $row): array
    {
        return [
            'id' => $row->id,
            'from_product_id' => $row->from_product_id,
            'from_family' => $row->from_family,
            'to_product_id' => $row->to_product_id,
            'to_family' => $row->to_family,
            'resource_group_id' => $row->resource_group_id,
            'changeover_minutes' => $row->changeover_minutes,
            'cleaning_minutes' => $row->cleaning_minutes,
            'is_active' => $row->is_active,
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function resourceGroupOptions(): array
    {
        return ResourceGroup::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (ResourceGroup $g) => ['value' => $g->id, 'label' => "{$g->code} — {$g->name}"])
            ->all();
    }
}
