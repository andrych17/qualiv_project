<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosBranch;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTerminalDevice;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3B, §3Q — Terminal & Device Management Controller.
 */
class PosTerminalController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'branch_id', 'profile_id', 'is_active', 'sort', 'direction', 'per_page');

        $terminals = PosTerminal::query()
            ->with(['branch', 'warehouse', 'profile', 'devices', 'currentSession.cashier'])
            ->filter($filters)
            ->orderBy('id')
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        $branches = PosBranch::query()->where('is_active', true)->get();
        $warehouses = Warehouse::query()->get(['id', 'name']);
        $profiles = PosProfile::query()->where('is_active', true)->get();

        return Inertia::render('POS/Terminals/Index', [
            'terminals' => $terminals,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'profiles' => $profiles,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): mixed
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'profile_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:30', 'unique:POS.pos_terminals,code'],
            'name' => ['required', 'string', 'max:150'],
            'receipt_prefix' => ['required', 'string', 'max:10', 'unique:POS.pos_terminals,receipt_prefix'],
            'default_price_list_id' => ['nullable', 'integer'],
            'default_tax_code' => ['nullable', 'string'],
            'receipt_template' => ['nullable', 'string'],
        ]);

        $terminal = PosTerminal::query()->create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->back()->with('success', 'Terminal berhasil ditambahkan.');
        }

        return response()->json($terminal->load(['branch', 'warehouse', 'profile']), 201);
    }

    public function update(Request $request, PosTerminal $terminal): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'profile_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'default_price_list_id' => ['nullable', 'integer'],
            'default_tax_code' => ['nullable', 'string'],
            'receipt_template' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $terminal->update($validated);

        return response()->json($terminal->fresh()->load(['branch', 'warehouse', 'profile']));
    }

    public function addDevice(Request $request, PosTerminal $terminal): JsonResponse
    {
        $validated = $request->validate([
            'device_type' => ['required', 'string'],
            'adapter_code' => ['required', 'string'],
            'connection_config' => ['nullable', 'array'],
        ]);

        $device = PosTerminalDevice::query()->create([
            'terminal_id' => $terminal->id,
            ...$validated,
        ]);

        return response()->json($device, 201);
    }
}
