<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreWorkCenterRequest;
use App\Modules\MES\Requests\UpdateWorkCenterRequest;
use App\Modules\MES\Services\WorkCenterService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3D Equipment Master Data — Work Centers (Entry). */
class WorkCenterController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name', 'type', 'created_at'];

    public function __construct(protected WorkCenterService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'type', 'sort', 'direction', 'per_page');

        $workCenters = WorkCenter::query()
            ->withCount('machines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (WorkCenter $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'area_line' => $w->area_line,
                'type' => $w->type,
                'machine_count' => $w->machines_count,
            ]);

        return Inertia::render('MES/WorkCenters/Index', [
            'workCenters' => $workCenters,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/WorkCenters/Create');
    }

    public function store(StoreWorkCenterRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.workCenters.index')->with('success', 'Work center created.');
    }

    public function edit(WorkCenter $workCenter): Response
    {
        return Inertia::render('MES/WorkCenters/Edit', [
            'workCenter' => $this->toFormData($workCenter),
        ]);
    }

    public function update(UpdateWorkCenterRequest $request, WorkCenter $workCenter)
    {
        $this->service->update($workCenter, $request->validated());

        return redirect()->route('mes.workCenters.index')->with('success', 'Work center updated.');
    }

    public function destroy(WorkCenter $workCenter)
    {
        $this->service->delete($workCenter);

        return redirect()->route('mes.workCenters.index')->with('success', 'Work center deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, WorkCenter::class, fn (WorkCenter $w) => $this->service->delete($w));
    }

    /** @return array<string, mixed> */
    private function toFormData(WorkCenter $workCenter): array
    {
        return [
            'id' => $workCenter->id,
            'code' => $workCenter->code,
            'name' => $workCenter->name,
            'area_line' => $workCenter->area_line,
            'type' => $workCenter->type,
        ];
    }
}
