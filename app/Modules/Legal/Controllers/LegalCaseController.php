<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Legal\Models\LegalCase;
use App\Modules\Legal\Requests\StoreLegalCaseRequest;
use App\Modules\Legal\Requests\UpdateLegalCaseRequest;
use App\Modules\Legal\Services\LegalCaseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalCaseController extends Controller
{
    public function __construct(
        protected LegalCaseService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status');

        $cases = LegalCase::query()
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (LegalCase $c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'code' => $c->code,
                'title' => $c->title,
                'status' => $c->status,
                'created_at_formatted' => $c->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Legal/Cases/Index', [
            'cases' => $cases,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/Cases/Create', [
            'customFields' => $this->customFields->formPayload(LegalCaseService::ENTITY),
        ]);
    }

    public function store(StoreLegalCaseRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('legal.cases.index')
            ->with('success', 'Case created.');
    }

    public function edit(LegalCase $case): Response
    {
        return Inertia::render('Legal/Cases/Edit', [
            'caseItem' => [
                'id' => $case->id,
                'code' => $case->code,
                'title' => $case->title,
                'status' => $case->status,
                'notes' => $case->notes,
            ],
            'customFields' => $this->customFields->formPayload(LegalCaseService::ENTITY, $case->id),
        ]);
    }

    public function update(UpdateLegalCaseRequest $request, LegalCase $case)
    {
        $this->service->update($case, $request->validated());

        return redirect()->route('legal.cases.index')
            ->with('success', 'Case updated.');
    }

    public function destroy(LegalCase $case)
    {
        $this->service->delete($case);

        return redirect()->route('legal.cases.index')
            ->with('success', 'Case deleted.');
    }
}
