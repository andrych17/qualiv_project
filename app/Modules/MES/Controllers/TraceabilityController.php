<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\MES\Services\TraceabilityService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3K Traceability & Genealogy (View/Report) — read-only, derived over §3H/§3I/§3J's own tables (no dedicated genealogy table). */
class TraceabilityController extends Controller
{
    public function __construct(protected TraceabilityService $service) {}

    public function index(Request $request): Response
    {
        $lotNumber = $request->query('lot_number');
        $serialNumber = $request->query('serial_number');
        $direction = $request->query('direction', 'backward');

        $lot = $lotNumber ? StockBatch::query()->where('batch_number', $lotNumber)->first() : null;
        $serial = $serialNumber ? StockSerial::query()->where('serial_number', $serialNumber)->first() : null;

        $result = null;
        $notFound = false;

        if ($lotNumber || $serialNumber) {
            if (! $lot && ! $serial) {
                $notFound = true;
            } else {
                $result = $direction === 'forward'
                    ? $this->service->forwardTrace($lot?->id, $serial?->id)
                    : $this->service->backwardTrace($lot?->id, $serial?->id);
            }
        }

        return Inertia::render('MES/Traceability/Index', [
            'filters' => ['lot_number' => $lotNumber, 'serial_number' => $serialNumber, 'direction' => $direction],
            'result' => $result,
            'notFound' => $notFound,
        ]);
    }
}
