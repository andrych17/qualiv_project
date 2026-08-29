<?php

namespace App\Modules\SysConfig\Requests;

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateConfigMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash',
            'menu_caption' => 'required|string|max:255',
            'menu_header' => 'nullable|string|max:100',
            'menu_link' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer',
            'seq' => 'required|integer|min:0|max:9999',
            'status_code' => 'required|in:A,I',
            'module_code' => 'nullable|string|max:20',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // ponytail: Rule::unique/exists treat "SCHEMA.table" as connection.table — query model instead
        $validator->after(function (Validator $validator) {
            /** @var ConfigMenu|null $menu */
            $menu = $this->route('menu');
            $menuId = $menu?->id;

            $code = $this->input('code');
            if ($code && ConfigMenu::query()
                ->where('app_code', 'NUSAEVO')
                ->where('code', $code)
                ->when($menuId, fn ($q) => $q->where('id', '!=', $menuId))
                ->exists()) {
                $validator->errors()->add('code', 'The code has already been taken.');
            }

            $parentId = $this->input('parent_id');
            if ($parentId) {
                if ((int) $parentId === (int) $menuId) {
                    $validator->errors()->add('parent_id', 'A menu cannot be its own parent.');
                } else {
                    $parent = ConfigMenu::query()->find($parentId);
                    if (! $parent) {
                        $validator->errors()->add('parent_id', 'The selected parent menu is invalid.');
                    } else {
                        // Check if parent is a descendant of this menu (prevent cyclic loops)
                        $curr = $parent;
                        $isDescendant = false;
                        while ($curr && $curr->parent_id) {
                            if ((int) $curr->parent_id === (int) $menuId) {
                                $isDescendant = true;
                                break;
                            }
                            $curr = ConfigMenu::query()->find($curr->parent_id);
                        }

                        if ($isDescendant) {
                            $validator->errors()->add('parent_id', 'Cannot set a descendant menu as the parent.');
                        } else {
                            // Calculate depth of parent from root
                            $parentDepth = 1;
                            $curr = $parent;
                            while ($curr && $curr->parent_id) {
                                $parentDepth++;
                                $curr = ConfigMenu::query()->find($curr->parent_id);
                            }

                            // Subtree height below this menu
                            $childIds = ConfigMenu::query()->where('parent_id', $menuId)->pluck('id');
                            $hasGrandchildren = $childIds->isNotEmpty() && ConfigMenu::query()->whereIn('parent_id', $childIds)->exists();
                            $subtreeHeight = $hasGrandchildren ? 3 : ($childIds->isNotEmpty() ? 2 : 1);

                            if ($parentDepth + $subtreeHeight > 3) {
                                $validator->errors()->add('parent_id', 'Menu hierarchy cannot exceed 3 levels.');
                            }
                        }
                    }
                }
            }
        });
    }
}
