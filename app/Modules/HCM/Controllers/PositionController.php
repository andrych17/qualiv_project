<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Position;
use App\Modules\HCM\Requests\StorePositionRequest;
use App\Modules\HCM\Services\OrgStructureService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['headcount_cap', 'is_active', 'id'];

    public function __construct(
        protected OrgStructureService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'org_unit_id', 'is_active', 'sort', 'direction', 'per_page');
        $perPage = TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15);
        $positions = $this->service->paginatePositions($filters, $perPage);

        return Inertia::render('HCM/Positions/Index', [
            'positions' => $positions,
            'jobs' => $this->service->allJobs(),
            'orgUnits' => $this->service->allOrgUnits(),
            'allPositions' => $this->service->allPositions(),
            'filters' => $filters,
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $this->service->createPosition($request->validated());

        return back()->with('success', 'Position created.');
    }

    public function update(StorePositionRequest $request, Position $position): RedirectResponse
    {
        $this->service->updatePosition($position, $request->validated());

        return back()->with('success', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $this->service->deletePosition($position);

        return back()->with('success', 'Position deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        return $this->bulkDestroyUsing($request, Position::class, fn (Position $p) => $this->service->deletePosition($p));
    }
}
