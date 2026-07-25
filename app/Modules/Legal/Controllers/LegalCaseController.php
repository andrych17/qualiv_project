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

    public function edit(LegalCase $legalCase): Response
    {
        return Inertia::render('Legal/Cases/Edit', [
            'caseItem' => [
                'id' => $legalCase->id,
                'code' => $legalCase->code,
                'title' => $legalCase->title,
                'status' => $legalCase->status,
                'notes' => $legalCase->notes,
            ],
            'customFields' => $this->customFields->formPayload(LegalCaseService::ENTITY, $legalCase->id),
        ]);
    }

    public function update(UpdateLegalCaseRequest $request, LegalCase $legalCase)
    {
        $this->service->update($legalCase, $request->validated());

        return redirect()->route('legal.cases.index')
            ->with('success', 'Case updated.');
    }

    public function destroy(LegalCase $legalCase)
    {
        $this->service->delete($legalCase);

        return redirect()->route('legal.cases.index')
            ->with('success', 'Case deleted.');
    }
}