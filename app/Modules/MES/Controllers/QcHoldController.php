<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Services\QcInspectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** MES_SPECS.md §3L — release a QC hold. Record-only (see `QcHold`'s own docblock) — releasing here doesn't unblock any Inventory operation, since nothing in Inventory blocked on it in the first place. */
class QcHoldController extends Controller
{
    public function __construct(protected QcInspectionService $service) {}

    public function release(Request $request, QcHold $qcHold)
    {
        try {
            $this->service->releaseHold($qcHold, $request->input('note'), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'QC hold released.');
    }
}
