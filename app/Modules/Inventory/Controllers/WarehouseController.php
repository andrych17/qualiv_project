<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreWarehouseRequest;
use App\Modules\Inventory\Requests\UpdateWarehouseRequest;
use App\Modules\Inventory\Services\LocationService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'created_at'];

    public function __construct(
        protected WarehouseService $service,
        protected LocationService $locations,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $warehouses = Warehouse::query()
            ->withCount('locations')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Warehouse $w) => [
                'id' => $w->id,
                'uuid' => $w->uuid,
                'name' => $w->name,
                'address' => $w->address,
                'location_count' => $w->locations_count,
                'is_active' => $w->is_active,
            ]);

        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Warehouses/Create');
    }

    public function store(StoreWarehouseRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.warehouses.index')->with('success', 'Warehouse created.');
    }

    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('Inventory/Warehouses/Edit', [
            'warehouse' => $warehouse->only('id', 'name', 'address', 'is_active'),
            'locations' => $this->locations->indented($warehouse->id)->map(fn ($l) => [
                'id' => $l->id,
                'code' => $l->code,
                'type' => $l->type,
                'depth' => $l->depth,
                'is_active' => $l->is_active,
                'child_count' => $l->children_count,
            ])->values(),
        ]);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $this->service->update($warehouse, $request->validated());

        return redirect()->route('inventory.warehouses.index')->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse)
    {
        $this->service->delete($warehouse);

        return redirect()->route('inventory.warehouses.index')->with('success', 'Warehouse deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Warehouse::class, fn (Warehouse $w) => $this->service->delete($w));
    }
}
