<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Requests\RejectBpnRequest;
use App\Modules\Legal\Requests\SubmitBpnRequest;
use App\Modules\Legal\Services\BpnSubmissionService;

class BpnSubmissionController extends Controller
{
    public function __construct(
        protected BpnSubmissionService $service,
    ) {}

    public function submit(SubmitBpnRequest $request, Deed $deed, BpnSubmission $bpnSubmission)
    {
        $data = $request->validated();
        $this->service->submit($bpnSubmission, $data['tracking_number'], $data['submitted_at'] ?? null);

        return back()->with('success', 'Submitted to BPN.');
    }

    public function markInProcess(Deed $deed, BpnSubmission $bpnSubmission)
    {
        $this->service->markInProcess($bpnSubmission);

        return back()->with('success', 'Marked in process.');
    }

    public function complete(Deed $deed, BpnSubmission $bpnSubmission)
    {
        $this->service->complete($bpnSubmission);

        return back()->with('success', 'Marked completed.');
    }

    public function reject(RejectBpnRequest $request, Deed $deed, BpnSubmission $bpnSubmission)
    {
        $this->service->reject($bpnSubmission, $request->validated()['reason']);

        return back()->with('success', 'Marked rejected.');
    }

    public function resubmit(Deed $deed, BpnSubmission $bpnSubmission)
    {
        $this->service->resubmit($bpnSubmission);

        return back()->with('success', 'Resubmission created.');
    }
}
