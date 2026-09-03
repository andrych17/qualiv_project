<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Requests\StoreBomRequest;
use App\Modules\PP\Requests\UpdateBomRequest;
use App\Modules\PP\Services\BomService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3D Discrete BOM (Entry). */
class BomController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['version', 'effective_from', 'created_at'];

    public function __construct(
        protected BomService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $boms = Bom::query()
            ->with('product:id,sku,name')
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Bom $b) => [
                'id' => $b->id,
                'product_sku' => $b->product?->sku,
                'product_name' => $b->product?->name,
                'version' => $b->version,
                'line_count' => $b->lines_count,
                'effective_from' => $b->effective_from->toDateString(),
                'is_active' => $b->is_active,
            ]);

        return Inertia::render('PP/Boms/Index', [
            'boms' => $boms,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/Boms/Create', [
            'customFields' => $this->customFields->formPayload(BomService::ENTITY),
        ]);
    }

    public function store(StoreBomRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.boms.index')->with('success', 'BOM created.');
    }

    public function edit(Bom $bom): Response
    {
        return Inertia::render('PP/Boms/Edit', [
            'bom' => $this->toFormData($bom),
            'customFields' => $this->customFields->formPayload(BomService::ENTITY, $bom->id),
        ]);
    }

    public function update(UpdateBomRequest $request, Bom $bom)
    {
        $this->service->update($bom, $request->validated());

        return redirect()->route('pp.boms.index')->with('success', 'BOM updated.');
    }

    public function destroy(Bom $bom)
    {
        $this->service->delete($bom);

        return redirect()->route('pp.boms.index')->with('success', 'BOM deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Bom::class, fn (Bom $b) => $this->service->delete($b));
    }

    /** @return array<string, mixed> */
    private function toFormData(Bom $bom): array
    {
        return [
            'id' => $bom->id,
            'product_id' => $bom->product_id,
            'product_label' => $bom->product ? "{$bom->product->sku} — {$bom->product->name}" : null,
            'version' => $bom->version,
            'effective_from' => $bom->effective_from->toDateString(),
            'effective_to' => $bom->effective_to?->toDateString(),
            'is_active' => $bom->is_active,
            'lines' => $bom->lines()->with('component:id,sku,name')->get()->map(fn ($l) => [
                'component_product_id' => $l->component_product_id,
                'component_label' => $l->component ? "{$l->component->sku} — {$l->component->name}" : null,
                'qty_per_parent_unit' => (float) $l->qty_per_parent_unit,
                'uom_code' => $l->uom_code,
                'scrap_pct' => (float) $l->scrap_pct,
            ]),
        ];
    }
}
