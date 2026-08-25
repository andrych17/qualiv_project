<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Requests\StoreProductRequest;
use App\Modules\Inventory\Requests\UpdateProductRequest;
use App\Modules\Inventory\Services\ProductCategoryService;
use App\Modules\Inventory\Services\ProductService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['sku', 'name', 'created_at'];

    public function __construct(
        protected ProductService $service,
        protected ProductCategoryService $categories,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'category_id', 'sort', 'direction', 'per_page');

        $products = Product::query()
            ->with('category:id,name', 'baseUom:id,code')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'sku' => $p->sku,
                'name' => $p->name,
                'category_name' => $p->category?->name,
                'base_uom_code' => $p->baseUom?->code,
                'costing_method' => $p->costing_method,
                'reorder_point' => (float) $p->reorder_point,
                'is_active' => $p->is_active,
                'created_at_formatted' => $p->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->categories->treeOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Create', [
            ...$this->formProps(),
            'customFields' => $this->customFields->formPayload(ProductService::ENTITY),
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.products.index')->with('success', 'Product created.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Inventory/Products/Edit', [
            ...$this->formProps(),
            'product' => $this->toFormData($product),
            'customFields' => $this->customFields->formPayload(ProductService::ENTITY, $product->id),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $this->service->update($product, $request->validated());

        return redirect()->route('inventory.products.index')->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
        $this->service->deactivate($product);

        return redirect()->route('inventory.products.index')->with('success', 'Product deactivated.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Product::class, fn (Product $p) => $this->service->deactivate($p));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'categories' => $this->categories->treeOptions(),
            'uoms' => Uom::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'base_uom_id' => $product->base_uom_id,
            'costing_method' => $product->costing_method,
            'reorder_point' => (float) $product->reorder_point,
            'reorder_quantity' => (float) $product->reorder_quantity,
            'tracking_mode' => $product->tracking_mode,
            'is_active' => $product->is_active,
            'barcodes' => $product->barcodes()->get(['barcode', 'type', 'unit_multiplier'])->map(fn ($b) => [
                'barcode' => $b->barcode,
                'type' => $b->type,
                'unit_multiplier' => (float) $b->unit_multiplier,
            ]),
            'uom_conversions' => $product->uomConversions()->get(['uom_id', 'conversion_factor'])->map(fn ($c) => [
                'uom_id' => $c->uom_id,
                'conversion_factor' => (float) $c->conversion_factor,
            ]),
        ];
    }
}
