<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Requests\StoreOkrCycleRequest;
use App\Modules\Performance\Requests\UpdateOkrCycleRequest;
use App\Modules\Performance\Services\OkrCycleService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3E OKR Cycles (Entry) — tenant-editable planning windows, e.g. "2026 Q3". */
class OkrCycleController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['label', 'start_date', 'created_at'];

    public function __construct(protected OkrCycleService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('sort', 'direction', 'per_page');

        $cycles = OkrCycle::query()
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'start_date', 'desc'),
                fn ($query) => $query->orderByDesc('start_date'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (OkrCycle $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'start_date_formatted' => $c->start_date?->format('d M Y'),
                'end_date_formatted' => $c->end_date?->format('d M Y'),
                'is_active' => $c->is_active,
            ]);

        return Inertia::render('Performance/OkrCycles/Index', [
            'cycles' => $cycles,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/OkrCycles/Create');
    }

    public function store(StoreOkrCycleRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.okrCycles.index')->with('success', 'Cycle created.');
    }

    public function edit(OkrCycle $okrCycle): Response
    {
        return Inertia::render('Performance/OkrCycles/Edit', [
            'cycle' => [
                'id' => $okrCycle->id,
                'label' => $okrCycle->label,
                'start_date' => $okrCycle->start_date->toDateString(),
                'end_date' => $okrCycle->end_date->toDateString(),
                'is_active' => $okrCycle->is_active,
            ],
        ]);
    }

    public function update(UpdateOkrCycleRequest $request, OkrCycle $okrCycle)
    {
        $this->service->update($okrCycle, $request->validated());

        return redirect()->route('performance.okrCycles.index')->with('success', 'Cycle updated.');
    }

    public function destroy(OkrCycle $okrCycle)
    {
        $this->service->delete($okrCycle);

        return redirect()->route('performance.okrCycles.index')->with('success', 'Cycle deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, OkrCycle::class, fn (OkrCycle $c) => $this->service->delete($c));
    }
}
