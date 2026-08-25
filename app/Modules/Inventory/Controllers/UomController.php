<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Requests\StoreUomRequest;
use App\Modules\Inventory\Requests\UpdateUomRequest;
use App\Modules\Inventory\Services\UomService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UomController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name'];

    public function __construct(protected UomService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $uoms = Uom::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Uom $u) => [
                'id' => $u->id,
                'code' => $u->code,
                'name' => $u->name,
                'is_active' => $u->is_active,
            ]);

        return Inertia::render('Inventory/Uoms/Index', [
            'uoms' => $uoms,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Uoms/Create');
    }

    public function store(StoreUomRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.uoms.index')->with('success', 'UoM created.');
    }

    public function edit(Uom $uom): Response
    {
        return Inertia::render('Inventory/Uoms/Edit', [
            'uom' => $uom->only('id', 'code', 'name', 'is_active'),
        ]);
    }

    public function update(UpdateUomRequest $request, Uom $uom)
    {
        $this->service->update($uom, $request->validated());

        return redirect()->route('inventory.uoms.index')->with('success', 'UoM updated.');
    }

    public function destroy(Uom $uom)
    {
        $this->service->delete($uom);

        return redirect()->route('inventory.uoms.index')->with('success', 'UoM deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Uom::class, fn (Uom $u) => $this->service->delete($u));
    }
}
