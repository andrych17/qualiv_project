<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Requests\RejectBpnRequest;
use App\Modules\Legal\Requests\SubmitBpnRequest;
use App\Modules\Legal\Services\BpnSubmissionService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BpnSubmissionController extends Controller
{
    private const SORTABLE = ['status', 'submission_type', 'submitted_at', 'created_at'];

    public function __construct(
        protected BpnSubmissionService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'sort', 'direction', 'per_page');

        $submissions = BpnSubmission::query()
            ->with(['deed:id,deed_number,matter_id', 'deed.matter:id,code'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (BpnSubmission $s) => [
                'id' => $s->id,
                'deed_id' => $s->deed_id,
                'deed_number' => $s->deed?->deed_number,
                'matter_code' => $s->deed?->matter?->code,
                'submission_type' => $s->submission_type,
                'status' => $s->status,
                'tracking_number' => $s->tracking_number,
                'pnbp_amount' => $s->pnbp_amount,
                'submitted_at' => $s->submitted_at?->toDateString(),
            ]);

        return Inertia::render('Legal/BpnSubmissions/Index', [
            'submissions' => $submissions,
            'filters' => $filters,
        ]);
    }

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
