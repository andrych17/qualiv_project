<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Requests\StoreWorkflowStepRequest;
use App\Modules\WNE\Requests\UpdateWorkflowStepRequest;
use App\Modules\WNE\Services\WorkflowDefinitionService;

class WorkflowStepController extends Controller
{
    public function __construct(
        protected WorkflowDefinitionService $definitions,
    ) {}

    public function store(StoreWorkflowStepRequest $request, WrkflowDefinition $definition)
    {
        $this->definitions->addStep($definition, $request->validated());

        return back()->with('success', 'Step added.');
    }

    public function update(UpdateWorkflowStepRequest $request, WrkflowDefinition $definition, WrkflowStep $step)
    {
        $this->definitions->updateStep($step, $request->validated());

        return back()->with('success', 'Step updated.');
    }

    public function destroy(WrkflowDefinition $definition, WrkflowStep $step)
    {
        $this->definitions->deleteStep($step);

        return back()->with('success', 'Step removed.');
    }
}
