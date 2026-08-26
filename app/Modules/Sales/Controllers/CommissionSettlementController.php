<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\CommissionPlan;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Requests\StoreCommissionSettlementRequest;
use App\Modules\Sales\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionSettlementController extends Controller
{
    public function __construct(protected CommissionService $commissionService) {}

    public function index(Request $request): Response
    {
        $settlements = CommissionSettlement::with(['rep', 'approver', 'lines'])
            ->when($request->rep_id, fn ($q, $r) => $q->where('rep_id', $r))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('period_start')
            ->paginate(20)
            ->withQueryString();

        $plans = CommissionPlan::with(['salesTeam', 'user'])->get();

        return Inertia::render('Sales/Commissions/Index', [
            'settlements' => $settlements,
            'plans' => $plans,
            'statuses' => CommissionSettlement::STATUSES,
            'reps' => User::query()->select(['id', 'name'])->get(),
            'filters' => $request->only(['rep_id', 'status']),
        ]);
    }

    public function store(StoreCommissionSettlementRequest $request): RedirectResponse
    {
        $settlement = $this->commissionService->createSettlement(
            (int) $request->rep_id,
            $request->period_start,
            $request->period_end
        );

        return redirect()->route('sales.commissions.show', $settlement)
            ->with('success', 'Commission settlement batch created.');
    }

    public function show(CommissionSettlement $settlement): Response
    {
        $settlement->load(['rep', 'approver', 'lines.commissionPlan', 'lines.salesOrderLine.order']);

        return Inertia::render('Sales/Commissions/Show', [
            'settlement' => $settlement,
        ]);
    }

    public function approve(CommissionSettlement $settlement, Request $request): RedirectResponse
    {
        $this->commissionService->approve($settlement, $request->user()->id);

        return back()->with('success', 'Commission settlement batch approved.');
    }

    public function markPaid(CommissionSettlement $settlement): RedirectResponse
    {
        $this->commissionService->markPaid($settlement);

        return back()->with('success', 'Commission settlement marked as paid.');
    }
}
