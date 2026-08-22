<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Requests\StoreLandObjectRequest;
use App\Modules\Legal\Requests\UpdateLandObjectRequest;
use App\Modules\Legal\Services\LandObjectService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandObjectController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['certificate_number', 'certificate_type', 'status', 'created_at'];

    public function __construct(
        protected LandObjectService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $landObjects = LandObject::query()
            ->with('currentOwner:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (LandObject $o) => [
                'id' => $o->id,
                'certificate_type' => $o->certificate_type,
                'certificate_number' => $o->certificate_number,
                'address' => $o->address,
                'owner_name' => $o->currentOwner?->name,
                'status' => $o->status,
                'created_at_formatted' => $o->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Legal/LandObjects/Index', [
            'landObjects' => $landObjects,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/LandObjects/Create', [
            'certificateTypes' => LandObject::CERTIFICATE_TYPES,
        ]);
    }

    public function store(StoreLandObjectRequest $request)
    {
        $landObject = $this->service->create($request->validated());

        return redirect()->route('legal.landObjects.edit', $landObject)
            ->with('success', 'Land object registered.');
    }

    public function edit(LandObject $landObject): Response
    {
        return Inertia::render('Legal/LandObjects/Edit', [
            'landObject' => [
                'id' => $landObject->id,
                'certificate_type' => $landObject->certificate_type,
                'certificate_number' => $landObject->certificate_number,
                'nib' => $landObject->nib,
                'address' => $landObject->address,
                'area_m2' => $landObject->area_m2,
                'njop_reference' => $landObject->njop_reference,
                'current_owner_partner_id' => $landObject->current_owner_partner_id,
                'status' => $landObject->status,
            ],
            'certificateTypes' => LandObject::CERTIFICATE_TYPES,
            'checkTypes' => DueDiligenceCheck::TYPES,
            'checks' => $landObject->dueDiligenceChecks()->with(['checker:id,name', 'overriddenByUser:id,name'])->orderByDesc('id')->get()->map(fn (DueDiligenceCheck $c) => [
                'id' => $c->id,
                'check_type' => $c->check_type,
                'status' => $c->status,
                'result_notes' => $c->result_notes,
                'checker_name' => $c->checker?->name,
                'checked_at' => $c->checked_at?->toDateTimeString(),
                'is_blocking' => $c->isBlocking(),
                'overridden_by_name' => $c->overriddenByUser?->name,
                'override_justification' => $c->override_justification,
            ]),
        ]);
    }

    public function update(UpdateLandObjectRequest $request, LandObject $landObject)
    {
        $this->service->update($landObject, $request->validated());

        return redirect()->route('legal.landObjects.edit', $landObject)
            ->with('success', 'Land object updated.');
    }

    public function destroy(LandObject $landObject)
    {
        $this->service->delete($landObject);

        return redirect()->route('legal.landObjects.index')
            ->with('success', 'Land object deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, LandObject::class, fn (LandObject $o) => $this->service->delete($o));
    }
}
