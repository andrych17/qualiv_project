<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Services\ReworkService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** MES_SPECS.md §3N — "Send to Rework": single write action turning a scrapped-and-flagged-for-rework output row into a child Production Order. */
class ReworkController extends Controller
{
    public function __construct(protected ReworkService $service) {}

    public function store(Request $request, ProductionOutput $productionOutput)
    {
        try {
            $child = $this->service->sendToRework($productionOutput, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('mes.prodOrders.show', $child->id)->with('success', "Sent to rework as {$child->order_number}.");
    }
}
