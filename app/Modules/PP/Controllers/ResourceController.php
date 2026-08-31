<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Requests\StoreResourceRequest;
use App\Modules\PP\Requests\UpdateResourceRequest;
use App\Modules\PP\Services\ResourceService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3E Resource Reference (Entry) — resource types no other Core module owns yet. */
class ResourceController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name', 'type', 'created_at'];

    public function __construct(protected ResourceService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'type', 'sort', 'direction', 'per_page');

        $resources = Resource::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Resource $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'code' => $r->code,
                'name' => $r->name,
                'capacity' => $r->capacity !== null ? (float) $r->capacity : null,
                'uom_code' => $r->uom_code,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('PP/Resources/Index', [
            'resources' => $resources,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/Resources/Create');
    }

    public function store(StoreResourceRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.resources.index')->with('success', 'Resource created.');
    }

    public function edit(Resource $resource): Response
    {
        return Inertia::render('PP/Resources/Edit', [
            'resource' => $this->toFormData($resource),
        ]);
    }

    public function update(UpdateResourceRequest $request, Resource $resource)
    {
        $this->service->update($resource, $request->validated());

        return redirect()->route('pp.resources.index')->with('success', 'Resource updated.');
    }

    public function destroy(Resource $resource)
    {
        $this->service->delete($resource);

        return redirect()->route('pp.resources.index')->with('success', 'Resource deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Resource::class, fn (Resource $r) => $this->service->delete($r));
    }

    /** @return array<string, mixed> */
    private function toFormData(Resource $resource): array
    {
        return [
            'id' => $resource->id,
            'type' => $resource->type,
            'code' => $resource->code,
            'name' => $resource->name,
            'capacity' => $resource->capacity !== null ? (float) $resource->capacity : null,
            'uom_code' => $resource->uom_code,
            'external_type' => $resource->external_type,
            'external_id' => $resource->external_id,
            'is_active' => $resource->is_active,
        ];
    }
}
