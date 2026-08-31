<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Requests\StoreRecipeRequest;
use App\Modules\PP\Requests\UpdateRecipeRequest;
use App\Modules\PP\Services\RecipeService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3D Process Recipe/Formula (Entry). */
class RecipeController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['version', 'batch_size', 'effective_from', 'created_at'];

    public function __construct(
        protected RecipeService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $recipes = Recipe::query()
            ->with('product:id,sku,name')
            ->withCount('ingredients')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Recipe $r) => [
                'id' => $r->id,
                'product_sku' => $r->product?->sku,
                'product_name' => $r->product?->name,
                'version' => $r->version,
                'batch_size' => (float) $r->batch_size,
                'uom_code' => $r->uom_code,
                'ingredient_count' => $r->ingredients_count,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('PP/Recipes/Index', [
            'recipes' => $recipes,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/Recipes/Create', [
            'customFields' => $this->customFields->formPayload(RecipeService::ENTITY),
        ]);
    }

    public function store(StoreRecipeRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.recipes.index')->with('success', 'Recipe created.');
    }

    public function edit(Recipe $recipe): Response
    {
        return Inertia::render('PP/Recipes/Edit', [
            'recipe' => $this->toFormData($recipe),
            'customFields' => $this->customFields->formPayload(RecipeService::ENTITY, $recipe->id),
        ]);
    }

    public function update(UpdateRecipeRequest $request, Recipe $recipe)
    {
        $this->service->update($recipe, $request->validated());

        return redirect()->route('pp.recipes.index')->with('success', 'Recipe updated.');
    }

    public function destroy(Recipe $recipe)
    {
        $this->service->delete($recipe);

        return redirect()->route('pp.recipes.index')->with('success', 'Recipe deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Recipe::class, fn (Recipe $r) => $this->service->delete($r));
    }

    /** @return array<string, mixed> */
    private function toFormData(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id,
            'product_id' => $recipe->product_id,
            'product_label' => $recipe->product ? "{$recipe->product->sku} — {$recipe->product->name}" : null,
            'version' => $recipe->version,
            'batch_size' => (float) $recipe->batch_size,
            'uom_code' => $recipe->uom_code,
            'expected_yield_pct' => (float) $recipe->expected_yield_pct,
            'expected_waste_pct' => (float) $recipe->expected_waste_pct,
            'effective_from' => $recipe->effective_from->toDateString(),
            'effective_to' => $recipe->effective_to?->toDateString(),
            'is_active' => $recipe->is_active,
            'ingredients' => $recipe->ingredients()->with('rawMaterial:id,sku,name')->get()->map(fn ($i) => [
                'raw_material_product_id' => $i->raw_material_product_id,
                'raw_material_label' => $i->rawMaterial ? "{$i->rawMaterial->sku} — {$i->rawMaterial->name}" : null,
                'qty_per_batch' => (float) $i->qty_per_batch,
                'uom_code' => $i->uom_code,
            ]),
        ];
    }
}
