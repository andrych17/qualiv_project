<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\Grade;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryStructureController extends Controller
{
    public function index(): Response
    {
        $structures = SalaryStructure::query()
            ->with(['grade', 'components.payrollComponent'])
            ->get();

        return Inertia::render('Payroll/Structures/Index', [
            'structures' => $structures,
            'grades' => Grade::all(),
            'components' => PayrollComponent::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'grade_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        SalaryStructure::query()->create($validated);

        return back()->with('success', 'Salary structure created.');
    }

    public function attachComponent(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $validated = $request->validate([
            'payroll_component_id' => ['required', 'integer'],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'formula_expression' => ['nullable', 'string'],
        ]);

        $structure->components()->create($validated);

        return back()->with('success', 'Component attached to salary structure.');
    }
}
