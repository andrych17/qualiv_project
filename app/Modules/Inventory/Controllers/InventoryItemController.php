<?php

// ponytail: Thin CRUD controller delegating transactions to InventoryItemService

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Inventory\Requests\StoreInventoryItemRequest;
use App\Modules\Inventory\Requests\UpdateInventoryItemRequest;
use App\Modules\Inventory\Services\InventoryItemService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryItemController extends Controller
{
    protected InventoryItemService $service;

    public function __construct(InventoryItemService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status');

        $items = InventoryItem::with('category')
            ->filter($filters)
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category_name' => $item->category?->name,
                'stock' => $item->stock,
                'minimum_stock' => $item->minimum_stock,
                'unit' => $item->unit,
                'status' => $item->status,
                'created_at_formatted' => $item->created_at?->format('d M Y'),
            ]);

        $categories = InventoryCategory::where('status', 'active')
            ->get(['id', 'name'])
            ->map(fn ($cat) => [
                'label' => $cat->name,
                'value' => $cat->id,
            ]);

        return Inertia::render('Inventory/Items/Index', [
            'items' => $items,
            'filters' => $filters,
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        $categories = InventoryCategory::where('status', 'active')
            ->get(['id', 'name'])
            ->map(fn ($cat) => [
                'label' => $cat->name,
                'value' => $cat->id,
            ]);

        return Inertia::render('Inventory/Items/Create', [
            'categories' => $categories,
        ]);
    }

    public function store(StoreInventoryItemRequest $request)
    {
        $this->service->createItem($request->validated());

        return redirect()->route('inventory.items.index')
            ->with('success', 'Inventory item created successfully.');
    }

    public function edit(InventoryItem $item): Response
    {
        $categories = InventoryCategory::where('status', 'active')
            ->get(['id', 'name'])
            ->map(fn ($cat) => [
                'label' => $cat->name,
                'value' => $cat->id,
            ]);

        return Inertia::render('Inventory/Items/Edit', [
            'item' => $item,
            'categories' => $categories,
        ]);
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $item)
    {
        $this->service->updateItem($item, $request->validated());

        return redirect()->route('inventory.items.index')
            ->with('success', 'Inventory item updated successfully.');
    }

    public function destroy(InventoryItem $item)
    {
        $this->service->deleteItem($item);

        return redirect()->route('inventory.items.index')
            ->with('success', 'Inventory item deleted successfully.');
    }
}
