<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosFloor;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Services\PosRestaurantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3M — Floor & Table Management Controller.
 */
class PosFloorController extends Controller
{
    public function __construct(
        protected PosRestaurantService $restaurantService,
    ) {}

    public function index(Request $request): Response
    {
        $floors = PosFloor::query()
            ->with(['tables.activeTransaction.lines'])
            ->get();

        return Inertia::render('POS/Floors/Index', [
            'floors' => $floors,
        ]);
    }

    public function storeFloor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'layout_ref' => ['nullable', 'string'],
        ]);

        $floor = PosFloor::query()->create($validated);

        return response()->json($floor, 201);
    }

    public function storeTable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'floor_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:20'],
            'seat_count' => ['required', 'integer', 'min:1'],
            'pos_x' => ['nullable', 'integer'],
            'pos_y' => ['nullable', 'integer'],
        ]);

        $table = PosTable::query()->create($validated);

        return response()->json($table, 201);
    }

    public function openTable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['required', 'integer'],
            'session_id' => ['required', 'integer'],
            'dining_mode' => ['nullable', 'string'],
        ]);

        $txn = $this->restaurantService->openTable(
            (int) $validated['table_id'],
            (int) $validated['session_id'],
            $validated['dining_mode'] ?? 'dine_in'
        );

        return response()->json($txn->load(['table', 'lines']));
    }

    public function moveTable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_table_id' => ['required', 'integer'],
            'to_table_id' => ['required', 'integer'],
        ]);

        $this->restaurantService->moveTable(
            (int) $validated['from_table_id'],
            (int) $validated['to_table_id']
        );

        return response()->json(['message' => 'Table moved successfully.']);
    }

    public function mergeTables(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_table_id' => ['required', 'integer'],
            'target_table_id' => ['required', 'integer'],
        ]);

        $this->restaurantService->mergeTables(
            (int) $validated['source_table_id'],
            (int) $validated['target_table_id']
        );

        return response()->json(['message' => 'Tables merged successfully.']);
    }
}
