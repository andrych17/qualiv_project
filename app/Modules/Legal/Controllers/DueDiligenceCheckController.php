<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Requests\OverrideDueDiligenceCheckRequest;
use App\Modules\Legal\Requests\RecordDueDiligenceResultRequest;
use App\Modules\Legal\Requests\StoreDueDiligenceCheckRequest;
use App\Modules\Legal\Services\DueDiligenceService;
use Illuminate\Support\Facades\Auth;

class DueDiligenceCheckController extends Controller
{
    public function __construct(
        protected DueDiligenceService $service,
    ) {}

    public function store(StoreDueDiligenceCheckRequest $request, LandObject $landObject)
    {
        $this->service->addCheck($landObject, $request->validated()['check_type']);

        return back()->with('success', 'Check added.');
    }

    public function recordResult(RecordDueDiligenceResultRequest $request, LandObject $landObject, DueDiligenceCheck $check)
    {
        $data = $request->validated();
        $this->service->recordResult($check, $data['status'], $data['result_notes'] ?? null, Auth::id());

        return back()->with('success', 'Check result recorded.');
    }

    public function override(OverrideDueDiligenceCheckRequest $request, LandObject $landObject, DueDiligenceCheck $check)
    {
        $this->service->override($check, $request->validated()['justification'], Auth::id());

        return back()->with('success', 'Check overridden.');
    }
}
