<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): ProductCategory
    {
        return ProductCategory::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(ProductCategory $category, array $data): ProductCategory
    {
        $category->update($this->attributes($data));

        return $category->refresh();
    }

    /**
     * §3C's location-deletion integrity rule, applied to categories: never orphan a
     * child category or leave a product pointing at a deleted row.
     */
    public function delete(ProductCategory $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages(['name' => 'This category has sub-categories — move or delete them first.']);
        }

        if ($category->products()->exists()) {
            throw ValidationException::withMessages(['name' => 'This category is assigned to products — reassign them first.']);
        }

        $category->delete();
    }

    /**
     * Flattened tree for a dropdown, root-first, each label indented by depth so the
     * hierarchy reads without a dedicated tree widget.
     *
     * @return list<array{id: int, label: string}>
     */
    public function treeOptions(): array
    {
        $all = ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_category_id']);
        $byParent = $all->groupBy('parent_category_id');

        $out = [];
        $this->flatten($byParent, null, 0, $out);

        return $out;
    }

    /** @param  Collection<int|string, Collection<int, ProductCategory>>  $byParent */
    private function flatten(Collection $byParent, ?int $parentId, int $depth, array &$out): void
    {
        foreach ($byParent->get($parentId, collect()) as $category) {
            $out[] = ['id' => $category->id, 'label' => str_repeat('— ', $depth).$category->name];
            $this->flatten($byParent, $category->id, $depth + 1, $out);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'parent_category_id' => empty($data['parent_category_id']) ? null : $data['parent_category_id'],
            'name' => $data['name'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
