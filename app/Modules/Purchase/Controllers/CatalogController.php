<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Requests\StoreCatalogItemRequest;
use App\Modules\Purchase\Requests\UpdateCatalogItemRequest;
use App\Modules\Purchase\Services\CatalogService;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function __construct(
        protected CatalogService $service,
    ) {}

    public function index(): Response
    {
        $items = PurCatalogItem::query()
            ->with(['category:id,name,kind', 'preferredSupplier:id,name'])
            ->orderBy('item_code')
            ->get()
            ->map(fn (PurCatalogItem $i) => [
                'id' => $i->id,
                'item_code' => $i->item_code,
                'description' => $i->description,
                'category_name' => $i->category?->name,
                'unit' => $i->unit,
                'preferred_supplier_name' => $i->preferredSupplier?->name,
                'negotiated_price' => $i->negotiated_price !== null ? (float) $i->negotiated_price : null,
                'price_valid_from' => $i->price_valid_from?->toDateString(),
                'price_valid_to' => $i->price_valid_to?->toDateString(),
                'source' => $i->source,
                'is_active' => $i->is_active,
            ]);

        return Inertia::render('Purchase/Catalog/Index', [
            'catalogItems' => $items,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchase/Catalog/Create', [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'vendors' => Partner::query()
                ->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(StoreCatalogItemRequest $request)
    {
        $item = $this->service->create($request->validated());

        return redirect()->route('purchase.catalog.index')->with('success', "Catalog item {$item->item_code} created.");
    }

    public function edit(PurCatalogItem $catalog): Response
    {
        return Inertia::render('Purchase/Catalog/Edit', [
            'item' => [
                'id' => $catalog->id,
                'item_code' => $catalog->item_code,
                'description' => $catalog->description,
                'category_id' => $catalog->category_id,
                'unit' => $catalog->unit,
                'preferred_supplier_id' => $catalog->preferred_supplier_id,
                'negotiated_price' => $catalog->negotiated_price !== null ? (float) $catalog->negotiated_price : null,
                'price_valid_from' => $catalog->price_valid_from?->toDateString(),
                'price_valid_to' => $catalog->price_valid_to?->toDateString(),
                'is_active' => $catalog->is_active,
            ],
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'vendors' => Partner::query()
                ->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCatalogItemRequest $request, PurCatalogItem $catalog)
    {
        $this->service->update($catalog, $request->validated());

        return redirect()->route('purchase.catalog.index')->with('success', "Catalog item {$catalog->item_code} updated.");
    }

    public function toggle(PurCatalogItem $catalog)
    {
        $this->service->toggleActive($catalog);

        return redirect()->back()->with('success', "Catalog item {$catalog->item_code} status updated.");
    }
}
