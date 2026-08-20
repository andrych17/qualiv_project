<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowTransition;
use App\Modules\WNE\Requests\StoreWorkflowTransitionRequest;
use App\Modules\WNE\Services\WorkflowDefinitionService;

class WorkflowTransitionController extends Controller
{
    public function __construct(
        protected WorkflowDefinitionService $definitions,
    ) {}

    public function store(StoreWorkflowTransitionRequest $request, WrkflowDefinition $definition)
    {
        $this->definitions->addTransition($definition, $request->validated());

        return back()->with('success', 'Transition added.');
    }

    public function destroy(WrkflowDefinition $definition, WrkflowTransition $transition)
    {
        $this->definitions->deleteTransition($transition);

        return back()->with('success', 'Transition removed.');
    }
}
