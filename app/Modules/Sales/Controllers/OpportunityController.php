<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesTeam;
use App\Modules\Sales\Requests\StoreOpportunityRequest;
use App\Modules\Sales\Services\OpportunityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends Controller
{
    public function __construct(protected OpportunityService $opportunityService) {}

    public function index(Request $request): Response
    {
        $opportunities = Opportunity::with(['customer', 'lead', 'owner', 'salesTeam'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->stage, fn ($q, $st) => $q->where('stage', $st))
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Sales/Opportunities/Index', [
            'opportunities' => $opportunities,
            'stages' => Opportunity::STAGES,
            'filters' => $request->only(['search', 'stage', 'owner_id']),
            'users' => User::query()->select(['id', 'name'])->get(),
            'teams' => SalesTeam::query()->where('is_active', true)->select(['id', 'name'])->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Opportunities/Create', [
            'stages' => Opportunity::STAGES,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'leads' => Lead::query()->select(['id', 'title'])->orderBy('title')->get(),
            'users' => User::query()->select(['id', 'name'])->get(),
            'teams' => SalesTeam::query()->where('is_active', true)->select(['id', 'name'])->get(),
        ]);
    }

    public function store(StoreOpportunityRequest $request): RedirectResponse
    {
        $opportunity = $this->opportunityService->create($request->validated(), $request->user()?->id);

        return redirect()->route('sales.opportunities.index')
            ->with('success', 'Opportunity created successfully.');
    }

    public function edit(Opportunity $opportunity): Response
    {
        return Inertia::render('Sales/Opportunities/Edit', [
            'opportunity' => $opportunity->load(['customer', 'lead', 'owner', 'salesTeam']),
            'stages' => Opportunity::STAGES,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'leads' => Lead::query()->select(['id', 'title'])->orderBy('title')->get(),
            'users' => User::query()->select(['id', 'name'])->get(),
            'teams' => SalesTeam::query()->where('is_active', true)->select(['id', 'name'])->get(),
        ]);
    }

    public function update(StoreOpportunityRequest $request, Opportunity $opportunity): RedirectResponse
    {
        $this->opportunityService->update($opportunity, $request->validated());

        return redirect()->route('sales.opportunities.index')
            ->with('success', 'Opportunity updated successfully.');
    }

    public function updateStage(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $request->validate([
            'stage' => ['required', 'in:'.implode(',', Opportunity::STAGES)],
            'loss_reason' => ['nullable', 'required_if:stage,lost', 'string', 'max:100'],
        ]);

        $this->opportunityService->updateStage($opportunity, $request->stage, $request->loss_reason);

        return back()->with('success', 'Opportunity stage updated.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        $opportunity->delete();

        return redirect()->route('sales.opportunities.index')
            ->with('success', 'Opportunity deleted.');
    }
}
