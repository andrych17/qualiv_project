<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Requests\StoreMaterialConsumptionRequest;
use App\Modules\MES\Services\MaterialConsumptionService;
use Illuminate\Validation\ValidationException;

/** MES_SPECS.md §3J Material Consumption — single write action off a Production Order (no index/edit; a row is a posted stock movement, immutable once made). */
class MaterialConsumptionController extends Controller
{
    public function __construct(protected MaterialConsumptionService $service) {}

    public function store(StoreMaterialConsumptionRequest $request, ProdOrder $prodOrder)
    {
        try {
            $this->service->record($prodOrder, $request->validated(), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Material movement recorded.');
    }
}
