<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Requests\StoreResourceGroupRequest;
use App\Modules\PP\Requests\UpdateResourceGroupRequest;
use App\Modules\PP\Services\ResourceGroupService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3E Resource Group Reference (Entry) — lets a planner request capacity from a group without picking a specific machine. */
class ResourceGroupController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name', 'created_at'];

    public function __construct(protected ResourceGroupService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $groups = ResourceGroup::query()
            ->withCount('members')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ResourceGroup $g) => [
                'id' => $g->id,
                'code' => $g->code,
                'name' => $g->name,
                'member_count' => $g->members_count,
                'is_active' => $g->is_active,
            ]);

        return Inertia::render('PP/ResourceGroups/Index', [
            'groups' => $groups,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/ResourceGroups/Create', [
            'resourceOptions' => $this->resourceOptions(),
        ]);
    }

    public function store(StoreResourceGroupRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.resourceGroups.index')->with('success', 'Resource group created.');
    }

    public function edit(ResourceGroup $resourceGroup): Response
    {
        return Inertia::render('PP/ResourceGroups/Edit', [
            'group' => $this->toFormData($resourceGroup),
            'resourceOptions' => $this->resourceOptions(),
        ]);
    }

    public function update(UpdateResourceGroupRequest $request, ResourceGroup $resourceGroup)
    {
        $this->service->update($resourceGroup, $request->validated());

        return redirect()->route('pp.resourceGroups.index')->with('success', 'Resource group updated.');
    }

    public function destroy(ResourceGroup $resourceGroup)
    {
        $this->service->delete($resourceGroup);

        return redirect()->route('pp.resourceGroups.index')->with('success', 'Resource group deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ResourceGroup::class, fn (ResourceGroup $g) => $this->service->delete($g));
    }

    /** @return array<string, mixed> */
    private function toFormData(ResourceGroup $group): array
    {
        return [
            'id' => $group->id,
            'code' => $group->code,
            'name' => $group->name,
            'is_active' => $group->is_active,
            'members' => $group->members()->with('ppResource:id,code,name')->get()->map(fn ($m) => [
                'resource_type' => $m->resource_type,
                'resource_ref_id' => $m->resource_ref_id,
                'resource_label' => $m->resource_type === 'pp_resource' && $m->ppResource
                    ? "{$m->ppResource->code} — {$m->ppResource->name}"
                    : null,
            ]),
        ];
    }

    /** @return list<array{value: int, label: string}> pp_resources options for the members list-input's "pp_resource" type */
    private function resourceOptions(): array
    {
        return Resource::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Resource $r) => ['value' => $r->id, 'label' => "{$r->code} — {$r->name}"])
            ->all();
    }
}
