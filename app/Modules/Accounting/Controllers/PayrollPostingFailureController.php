<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\PayrollPostingFailure;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\PayrollGlPostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** §3S review queue — "fails loudly and queues for review rather than posting to a suspense account silently" (spec rule). Retry re-attempts posting after the underlying mapping/period problem is fixed. */
class PayrollPostingFailureController extends Controller
{
    public function __construct(private readonly PayrollGlPostingService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $failures = PayrollPostingFailure::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Accounting/PayrollPostingFailures/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'failures' => $failures->map(fn (PayrollPostingFailure $f) => [
                'id' => $f->id,
                'subject_id' => $f->subject_id,
                'reason' => $f->reason,
                'status' => $f->status,
                'created_at' => $f->created_at->format('d M Y H:i'),
                'resolved_at' => $f->resolved_at?->format('d M Y H:i'),
            ]),
        ]);
    }

    public function retry(PayrollPostingFailure $failure)
    {
        try {
            $this->service->retry($failure);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $failure->refresh();
        if ($failure->status === PayrollPostingFailure::STATUS_PENDING) {
            return back()->withErrors(['failure' => 'Still failing: '.$failure->reason]);
        }

        return back()->with('success', 'Posted successfully.');
    }
}
