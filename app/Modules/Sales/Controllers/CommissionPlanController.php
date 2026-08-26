<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\CommissionPlan;
use App\Modules\Sales\Models\SalesTeam;
use App\Modules\Sales\Requests\StoreCommissionPlanRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CommissionPlanController extends Controller
{
    public function index(): Response
    {
        $plans = CommissionPlan::with(['salesTeam', 'user'])->get();
        $teams = SalesTeam::where('is_active', true)->get();
        $users = User::query()->select(['id', 'name'])->get();

        return Inertia::render('Sales/Master/CommissionPlans/Index', [
            'plans' => $plans,
            'teams' => $teams,
            'users' => $users,
        ]);
    }

    public function store(StoreCommissionPlanRequest $request): RedirectResponse
    {
        CommissionPlan::create($request->validated());

        return back()->with('success', 'Commission Plan created.');
    }

    public function update(StoreCommissionPlanRequest $request, CommissionPlan $commissionPlan): RedirectResponse
    {
        $commissionPlan->update($request->validated());

        return back()->with('success', 'Commission Plan updated.');
    }

    public function destroy(CommissionPlan $commissionPlan): RedirectResponse
    {
        $commissionPlan->delete();

        return back()->with('success', 'Commission Plan deleted.');
    }
}
