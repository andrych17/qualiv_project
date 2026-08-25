<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Requests\StoreProductCategoryRequest;
use App\Modules\Inventory\Requests\UpdateProductCategoryRequest;
use App\Modules\Inventory\Services\ProductCategoryService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name'];

    public function __construct(protected ProductCategoryService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $categories = ProductCategory::query()
            ->with('parent:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ProductCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'parent_name' => $c->parent?->name,
                'is_active' => $c->is_active,
            ]);

        return Inertia::render('Inventory/Categories/Index', [
            'categories' => $categories,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Categories/Create', [
            'categoryOptions' => $this->service->treeOptions(),
        ]);
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.categories.index')->with('success', 'Category created.');
    }

    public function edit(ProductCategory $category): Response
    {
        return Inertia::render('Inventory/Categories/Edit', [
            'category' => $category->only('id', 'name', 'parent_category_id', 'is_active'),
            'categoryOptions' => array_values(array_filter($this->service->treeOptions(), fn ($opt) => $opt['id'] !== $category->id)),
        ]);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category)
    {
        $this->service->update($category, $request->validated());

        return redirect()->route('inventory.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(ProductCategory $category)
    {
        $this->service->delete($category);

        return redirect()->route('inventory.categories.index')->with('success', 'Category deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ProductCategory::class, fn (ProductCategory $c) => $this->service->delete($c));
    }
}
