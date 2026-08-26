<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\PayrollComponent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollComponentController extends Controller
{
    public function index(): Response
    {
        $components = PayrollComponent::query()
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return Inertia::render('Payroll/Components/Index', [
            'components' => $components,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'in:earning,deduction'],
            'category' => ['required', 'string', 'in:fixed,formula,statutory,variable_input'],
            'calculation_basis' => ['required', 'string', 'in:flat,hourly,daily,percent_of_basic'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_bpjs_basis' => ['nullable', 'boolean'],
            'gl_account_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (PayrollComponent::query()->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => 'The component code has already been taken.']);
        }

        PayrollComponent::query()->create($validated);

        return back()->with('success', 'Payroll component created.');
    }

    public function update(Request $request, PayrollComponent $component): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'in:earning,deduction'],
            'category' => ['required', 'string', 'in:fixed,formula,statutory,variable_input'],
            'calculation_basis' => ['required', 'string', 'in:flat,hourly,daily,percent_of_basic'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_bpjs_basis' => ['nullable', 'boolean'],
            'gl_account_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $component->update($validated);

        return back()->with('success', 'Payroll component updated.');
    }
}
