<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\WrkflowCategory;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowTransition;
use App\Modules\WNE\Requests\StoreWorkflowDefinitionRequest;
use App\Modules\WNE\Requests\UpdateWorkflowDefinitionRequest;
use App\Modules\WNE\Services\WorkflowDefinitionService;
use Inertia\Inertia;
use Inertia\Response;

/** §3B — Workflow Definition Builder (header + list/publish actions; step/transition CRUD are their own controllers). */
class WorkflowDefinitionController extends Controller
{
    public function __construct(
        protected WorkflowDefinitionService $definitions,
    ) {}

    public function index(): Response
    {
        $rows = WrkflowDefinition::query()
            ->with('category')
            ->withCount(['versions as has_draft_count' => fn ($q) => $q->whereNull('published_by')])
            ->orderBy('name')
            ->get()
            ->map(fn (WrkflowDefinition $definition) => [
                'id' => $definition->id,
                'code' => $definition->code,
                'name' => $definition->name,
                'category' => $definition->category?->name,
                'status' => $definition->status,
                'published_version_no' => $definition->currentPublishedVersion()?->version_no,
                'has_unpublished_draft' => $definition->has_draft_count > 0,
            ]);

        return Inertia::render('WNE/Workflows/Index', ['definitions' => $rows]);
    }

    public function create(): Response
    {
        return Inertia::render('WNE/Workflows/Create', [
            'categories' => WrkflowCategory::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreWorkflowDefinitionRequest $request)
    {
        $definition = $this->definitions->create($request->validated(), $request->user()->id);

        return to_route('wne.workflows.edit', $definition)->with('success', 'Workflow created — add steps to build it out.');
    }

    public function edit(WrkflowDefinition $definition): Response
    {
        // Opening the builder always resolves (and, if needed, forks) the editable draft —
        // matches §3B's "editing a published definition always edits a new draft" rule.
        $draft = $this->definitions->draftVersion($definition);
        $steps = $draft->steps()->orderBy('id')->get();

        return Inertia::render('WNE/Workflows/Edit', [
            'definition' => [
                'id' => $definition->id,
                'code' => $definition->code,
                'name' => $definition->name,
                'description' => $definition->description,
                'category_id' => $definition->category_id,
                'status' => $definition->status,
            ],
            'categories' => WrkflowCategory::query()->orderBy('name')->get(['id', 'name']),
            'draftVersionNo' => $draft->version_no,
            'publishedVersionNo' => $definition->currentPublishedVersion()?->version_no,
            'steps' => $steps->map(fn ($s) => [
                'id' => $s->id,
                'step_code' => $s->step_code,
                'type' => $s->type,
                'config' => $s->config,
                // Never round-trip decrypted auth header values into an Inertia page prop
                // (page source / history) — the builder only needs to know whether a value
                // is already set, so it can offer "leave unchanged" vs "replace" on edit.
                'has_webhook_auth_headers' => ! empty($s->webhook_auth_headers),
                'pos_x' => $s->pos_x,
                'pos_y' => $s->pos_y,
                'is_entry_step' => $s->is_entry_step,
            ]),
            'transitions' => WrkflowTransition::query()
                ->whereIn('from_step_id', $steps->pluck('id'))
                ->orderBy('seq')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'from_step_id' => $t->from_step_id,
                    'to_step_id' => $t->to_step_id,
                    'condition_expression' => $t->condition_expression,
                    'seq' => $t->seq,
                ]),
        ]);
    }

    public function update(UpdateWorkflowDefinitionRequest $request, WrkflowDefinition $definition)
    {
        $this->definitions->updateHeader($definition, $request->validated());

        return back()->with('success', 'Workflow details updated.');
    }

    public function destroy(WrkflowDefinition $definition)
    {
        $this->definitions->delete($definition);

        return to_route('wne.workflows.index')->with('success', 'Workflow deleted.');
    }

    public function publish(WrkflowDefinition $definition)
    {
        $this->definitions->publish($definition, request()->user()->id);

        return back()->with('success', 'Workflow published.');
    }

    public function unpublish(WrkflowDefinition $definition)
    {
        $this->definitions->unpublish($definition);

        return back()->with('success', 'Workflow unpublished — running instances are unaffected.');
    }
}
