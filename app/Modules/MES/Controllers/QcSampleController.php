<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Requests\StoreQcSampleRequest;
use App\Modules\MES\Services\QcInspectionService;

/** MES_SPECS.md §3L — recording a QC sample, an independent action off an order or a batch phase (see QcInspectionService's own docblock for why it isn't threaded into Complete/Complete-Phase). */
class QcSampleController extends Controller
{
    public function __construct(protected QcInspectionService $service) {}

    public function store(StoreQcSampleRequest $request)
    {
        $sample = $this->service->recordSample($request->validated(), $request->user()->id);

        return back()->with('success', "QC sample {$sample->sample_number} recorded.");
    }
}
