<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\ResourceType;
use App\Modules\Schedule\Requests\StoreResourceRequest;
use App\Modules\Schedule\Requests\UpdateResourceRequest;
use App\Modules\Schedule\Services\ResourceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3D — Resource Management: bookable rooms/equipment/vehicles/staff. */
class ResourceController extends Controller
{
    private const SORTABLE = ['name'];

    public function __construct(
        protected ResourceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'resource_type_id', 'status', 'sort', 'direction', 'per_page');

        $resources = Resource::query()
            ->with('resourceType:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['resource_type_id'] ?? null, fn ($q, $typeId) => $q->where('resource_type_id', $typeId))
            ->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name', 'asc'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Resource $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'resource_type_name' => $r->resourceType?->name,
                'location_notes' => $r->location_notes,
                'capacity' => $r->capacity,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('Schedule/Resources/Index', [
            'resources' => $resources,
            'filters' => $filters,
            'resourceTypes' => ResourceType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Schedule/Resources/Create', $this->formProps());
    }

    public function store(StoreResourceRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('schedule.resources.index')->with('success', 'Resource created.');
    }

    public function edit(Resource $resource): Response
    {
        return Inertia::render('Schedule/Resources/Edit', [
            ...$this->formProps(),
            'resource' => $this->toFormData($resource),
        ]);
    }

    public function update(UpdateResourceRequest $request, Resource $resource)
    {
        $this->service->update($resource, $request->validated());

        return redirect()->route('schedule.resources.index')->with('success', 'Resource updated.');
    }

    public function destroy(Resource $resource)
    {
        $this->service->deactivate($resource);

        return redirect()->route('schedule.resources.index')->with('success', 'Resource deactivated.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'resourceTypes' => ResourceType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Resource $resource): array
    {
        return [
            'id' => $resource->id,
            'resource_type_id' => $resource->resource_type_id,
            'name' => $resource->name,
            'location_notes' => $resource->location_notes,
            'capacity' => $resource->capacity,
            'is_active' => $resource->is_active,
            'working_hours' => $resource->workingHours()->get(['day_of_week', 'start_time', 'end_time'])->map(fn ($h) => [
                'day_of_week' => $h->day_of_week,
                'start_time' => substr($h->start_time, 0, 5),
                'end_time' => substr($h->end_time, 0, 5),
            ]),
        ];
    }
}
