<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Requests\StoreBadgeDefinitionRequest;
use App\Modules\Performance\Requests\UpdateBadgeDefinitionRequest;
use App\Modules\Performance\Services\BadgeDefinitionService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3I — tenant-editable badge/rule library ("what can be earned and how"). */
class BadgeDefinitionController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'trigger_type', 'created_at'];

    public function __construct(protected BadgeDefinitionService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('sort', 'direction', 'per_page');

        $badges = BadgeDefinition::query()
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name', 'asc'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (BadgeDefinition $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'trigger_type' => $b->trigger_type,
                'trigger_params' => $b->trigger_params,
                'icon' => $b->icon,
                'is_active' => $b->is_active,
            ]);

        return Inertia::render('Performance/BadgeDefinitions/Index', [
            'badges' => $badges,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/BadgeDefinitions/Create');
    }

    public function store(StoreBadgeDefinitionRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.badgeDefinitions.index')->with('success', 'Badge created.');
    }

    public function edit(BadgeDefinition $badgeDefinition): Response
    {
        return Inertia::render('Performance/BadgeDefinitions/Edit', [
            'badge' => [
                'id' => $badgeDefinition->id,
                'name' => $badgeDefinition->name,
                'trigger_type' => $badgeDefinition->trigger_type,
                'trigger_params' => $badgeDefinition->trigger_params,
                'icon' => $badgeDefinition->icon,
                'is_active' => $badgeDefinition->is_active,
            ],
        ]);
    }

    public function update(UpdateBadgeDefinitionRequest $request, BadgeDefinition $badgeDefinition)
    {
        $this->service->update($badgeDefinition, $request->validated());

        return redirect()->route('performance.badgeDefinitions.index')->with('success', 'Badge updated.');
    }

    public function destroy(BadgeDefinition $badgeDefinition)
    {
        $this->service->delete($badgeDefinition);

        return redirect()->route('performance.badgeDefinitions.index')->with('success', 'Badge deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, BadgeDefinition::class, fn (BadgeDefinition $b) => $this->service->delete($b));
    }
}
