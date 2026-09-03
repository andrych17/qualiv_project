<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosKdsStation;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use App\Modules\POS\Services\PosRestaurantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3O — Kitchen Display System Controller.
 */
class PosKdsController extends Controller
{
    public function __construct(
        protected PosRestaurantService $restaurantService,
    ) {}

    public function index(Request $request): Response
    {
        $stations = PosKdsStation::query()->get();
        $stationId = $request->input('station_id', $stations->first()?->id);

        $queue = $this->restaurantService->getKdsQueue($stationId ? (int) $stationId : null);

        return Inertia::render('POS/KDS/Index', [
            'stations' => $stations,
            'selectedStationId' => $stationId,
            'initialQueue' => $queue,
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $stationId = $request->input('station_id');
        $queue = $this->restaurantService->getKdsQueue($stationId ? (int) $stationId : null);

        return response()->json($queue);
    }

    public function routeOrder(PosTxnHdr $txn): JsonResponse
    {
        $this->restaurantService->routeToKds($txn);

        return response()->json(['message' => 'Order routed to KDS successfully.']);
    }

    public function updateStatus(Request $request, PosTxnLine $line): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:new,preparing,ready,served,refired'],
            'note' => ['nullable', 'string'],
        ]);

        $updated = $this->restaurantService->updateKdsLineStatus(
            $line->id,
            $validated['status'],
            auth()->id() ?: 1,
            $validated['note'] ?? null
        );

        return response()->json($updated);
    }
}
