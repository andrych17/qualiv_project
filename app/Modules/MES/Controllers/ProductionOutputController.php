<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Requests\StoreProductionOutputRequest;
use App\Modules\MES\Services\ProductionOutputService;
use Illuminate\Validation\ValidationException;

/** MES_SPECS.md §3J Production Output — single write action off a Production Order (no index/edit; a row is a posted stock movement, immutable once made). */
class ProductionOutputController extends Controller
{
    public function __construct(protected ProductionOutputService $service) {}

    public function store(StoreProductionOutputRequest $request, ProdOrder $prodOrder)
    {
        try {
            $this->service->record($prodOrder, $request->validated(), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Production output recorded.');
    }
}
