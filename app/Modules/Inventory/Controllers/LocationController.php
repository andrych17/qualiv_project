<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreLocationRequest;
use App\Modules\Inventory\Requests\UpdateLocationRequest;
use App\Modules\Inventory\Services\LocationService;
use Inertia\Inertia;
use Inertia\Response;

/** §3C — tree CRUD nested under a warehouse, same interaction pattern as DMS's Folder Management. */
class LocationController extends Controller
{
    public function __construct(protected LocationService $service) {}

    public function create(Warehouse $warehouse): Response
    {
        return Inertia::render('Inventory/Locations/Create', [
            'warehouse' => $warehouse->only('id', 'name'),
            'parents' => $this->service->parentOptions($warehouse->id),
        ]);
    }

    public function store(StoreLocationRequest $request, Warehouse $warehouse)
    {
        $this->service->create($warehouse->id, $request->validated());

        return redirect()->route('inventory.warehouses.edit', $warehouse)->with('success', 'Location created.');
    }

    public function edit(Warehouse $warehouse, Location $location): Response
    {
        return Inertia::render('Inventory/Locations/Edit', [
            'warehouse' => $warehouse->only('id', 'name'),
            'location' => [
                ...$location->only('id', 'code', 'parent_location_id', 'type', 'is_active'),
                'barcodes' => $location->barcodes()->get(['barcode'])->map->only('barcode'),
            ],
            'parents' => $this->service->parentOptions($warehouse->id, $location->id),
        ]);
    }

    public function update(UpdateLocationRequest $request, Warehouse $warehouse, Location $location)
    {
        $this->service->update($location, $request->validated());

        return redirect()->route('inventory.warehouses.edit', $warehouse)->with('success', 'Location updated.');
    }

    public function destroy(Warehouse $warehouse, Location $location)
    {
        $this->service->delete($location);

        return redirect()->route('inventory.warehouses.edit', $warehouse)->with('success', 'Location deleted.');
    }
}
