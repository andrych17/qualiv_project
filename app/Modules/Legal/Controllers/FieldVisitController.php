<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Requests\CheckInFieldVisitRequest;
use App\Modules\Legal\Requests\CompleteFieldVisitRequest;
use App\Modules\Legal\Requests\StoreFieldVisitRequest;
use App\Modules\Legal\Requests\UpdateFieldVisitRequest;
use App\Modules\Legal\Services\FieldVisitService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FieldVisitController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['status', 'checked_in_at', 'created_at'];

    public function __construct(
        protected FieldVisitService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'sort', 'direction', 'per_page');

        $visits = FieldVisit::query()
            ->with(['visitType:id,name', 'matter:id,code,title', 'assignee:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (FieldVisit $v) => [
                'id' => $v->id,
                'visit_type_name' => $v->visitType?->name,
                'matter_code' => $v->matter?->code,
                'assignee_name' => $v->assignee?->name,
                'status' => $v->status,
                'checked_in_at' => $v->checked_in_at?->toDateTimeString(),
            ]);

        return Inertia::render('Legal/FieldVisits/Index', [
            'visits' => $visits,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/FieldVisits/Create', $this->formProps());
    }

    public function store(StoreFieldVisitRequest $request)
    {
        $this->service->schedule($request->validated());

        return redirect()->route('legal.fieldVisits.index')
            ->with('success', 'Field visit scheduled.');
    }

    public function edit(FieldVisit $fieldVisit): Response
    {
        $fieldVisit->loadMissing('visitType');

        return Inertia::render('Legal/FieldVisits/Edit', array_merge($this->formProps(), [
            'visit' => [
                'id' => $fieldVisit->id,
                'matter_id' => $fieldVisit->matter_id,
                'land_object_id' => $fieldVisit->land_object_id,
                'deed_id' => $fieldVisit->deed_id,
                'visit_type_id' => $fieldVisit->visit_type_id,
                'assigned_to' => $fieldVisit->assigned_to,
                'status' => $fieldVisit->status,
                'checked_in_at' => $fieldVisit->checked_in_at?->toDateTimeString(),
                'gps_lat' => $fieldVisit->gps_lat,
                'gps_lng' => $fieldVisit->gps_lng,
                'checklist_result' => $fieldVisit->checklist_result ?? $this->service->blankChecklist($fieldVisit->visitType),
                'notes' => $fieldVisit->notes,
            ],
        ]));
    }

    public function update(UpdateFieldVisitRequest $request, FieldVisit $fieldVisit)
    {
        $this->service->update($fieldVisit, $request->validated());

        return redirect()->route('legal.fieldVisits.edit', $fieldVisit)
            ->with('success', 'Field visit updated.');
    }

    public function checkIn(CheckInFieldVisitRequest $request, FieldVisit $fieldVisit)
    {
        $data = $request->validated();
        $this->service->checkIn($fieldVisit, (float) $data['gps_lat'], (float) $data['gps_lng']);

        return back()->with('success', 'Checked in.');
    }

    public function complete(CompleteFieldVisitRequest $request, FieldVisit $fieldVisit)
    {
        $data = $request->validated();
        $this->service->complete($fieldVisit, $data['checklist_result'] ?? [], $data['notes'] ?? null);

        return back()->with('success', 'Visit completed.');
    }

    public function destroy(FieldVisit $fieldVisit)
    {
        $this->service->delete($fieldVisit);

        return redirect()->route('legal.fieldVisits.index')
            ->with('success', 'Field visit deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, FieldVisit::class, fn (FieldVisit $v) => $this->service->delete($v));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'visitTypes' => FieldVisitType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::query()->orderByDesc('id')->limit(200)->get(['id', 'code', 'title']),
            'landObjects' => LandObject::query()->orderByDesc('id')->limit(200)->get(['id', 'certificate_number']),
            'deeds' => Deed::query()->orderByDesc('id')->limit(200)->get(['id', 'deed_number', 'uuid']),
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
