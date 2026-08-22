<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Requests\StoreMatterRequest;
use App\Modules\Legal\Requests\UpdateMatterRequest;
use App\Modules\Legal\Services\MatterService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MatterController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'title', 'matter_type', 'status', 'opened_at', 'target_close_at', 'created_at'];

    public function __construct(
        protected MatterService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $matters = Matter::query()
            ->with(['partner:id,name', 'assignee:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Matter $m) => [
                'id' => $m->id,
                'uuid' => $m->uuid,
                'code' => $m->code,
                'title' => $m->title,
                'matter_type' => $m->matter_type,
                'partner_name' => $m->partner?->name,
                'assignee_name' => $m->assignee?->name,
                'status' => $m->status,
                'opened_at_formatted' => $m->opened_at?->format('d M Y'),
                'target_close_at_formatted' => $m->target_close_at?->format('d M Y'),
                'notes' => $m->notes,
            ]);

        return Inertia::render('Legal/Matters/Index', [
            'matters' => $matters,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/Matters/Create', [
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
            'customFields' => $this->customFields->formPayload(MatterService::ENTITY),
        ]);
    }

    public function store(StoreMatterRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('legal.matters.index')
            ->with('success', 'Matter opened.');
    }

    public function edit(Matter $matter): Response
    {
        return Inertia::render('Legal/Matters/Edit', [
            'matter' => [
                'id' => $matter->id,
                'code' => $matter->code,
                'title' => $matter->title,
                'matter_type' => $matter->matter_type,
                'partner_id' => $matter->partner_id,
                'assigned_to' => $matter->assigned_to,
                'status' => $matter->status,
                'opened_at' => $matter->opened_at?->toDateString(),
                'target_close_at' => $matter->target_close_at?->toDateString(),
                'converted_from_lead_id' => $matter->converted_from_lead_id,
                'notes' => $matter->notes,
            ],
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
            'customFields' => $this->customFields->formPayload(MatterService::ENTITY, $matter->id),
        ]);
    }

    public function update(UpdateMatterRequest $request, Matter $matter)
    {
        $this->service->update($matter, $request->validated());

        return redirect()->route('legal.matters.index')
            ->with('success', 'Matter updated.');
    }

    public function destroy(Matter $matter)
    {
        $this->service->delete($matter);

        return redirect()->route('legal.matters.index')
            ->with('success', 'Matter deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Matter::class, fn (Matter $matter) => $this->service->delete($matter));
    }
}
