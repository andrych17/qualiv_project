<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosProfile;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3A — POS Profile & Capability Matrix Controller.
 */
class PosProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'base_type', 'is_active', 'sort', 'direction', 'per_page');

        $profiles = PosProfile::query()
            ->filter($filters)
            ->withCount('terminals')
            ->orderBy('id')
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('POS/Profiles/Index', [
            'profiles' => $profiles,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:POS.pos_profiles,code'],
            'name' => ['required', 'string', 'max:150'],
            'base_type' => ['required', 'string', 'in:retail,restaurant,service'],
            'requires_barcode' => ['nullable', 'boolean'],
            'touch_menu' => ['nullable', 'boolean'],
            'multi_uom' => ['nullable', 'boolean'],
            'table_management' => ['nullable', 'boolean'],
            'modifiers_enabled' => ['nullable', 'boolean'],
            'kds_enabled' => ['nullable', 'boolean'],
            'recipe_consumption' => ['nullable', 'boolean'],
            'loyalty_enabled' => ['nullable', 'boolean'],
            'promotion_enabled' => ['nullable', 'boolean'],
            'offline_enabled' => ['nullable', 'boolean'],
        ]);

        $profile = PosProfile::query()->create($validated);

        return response()->json($profile, 201);
    }

    public function update(Request $request, PosProfile $profile): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'requires_barcode' => ['nullable', 'boolean'],
            'touch_menu' => ['nullable', 'boolean'],
            'multi_uom' => ['nullable', 'boolean'],
            'table_management' => ['nullable', 'boolean'],
            'modifiers_enabled' => ['nullable', 'boolean'],
            'kds_enabled' => ['nullable', 'boolean'],
            'recipe_consumption' => ['nullable', 'boolean'],
            'loyalty_enabled' => ['nullable', 'boolean'],
            'promotion_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $profile->update($validated);

        return response()->json($profile->fresh());
    }
}
