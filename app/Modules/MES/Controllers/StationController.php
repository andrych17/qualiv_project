<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\Station;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreStationRequest;
use App\Modules\MES\Requests\UpdateStationRequest;
use App\Modules\MES\Services\StationService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3D Equipment Master Data — Stations (Entry), the Shop Floor UI target (§3G). */
class StationController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name'];

    public function __construct(protected StationService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $stations = Station::query()
            ->with(['workCenter:id,code,name', 'machine:id,code,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Station $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'work_center_label' => $s->workCenter ? "{$s->workCenter->code} — {$s->workCenter->name}" : null,
                'machine_label' => $s->machine ? "{$s->machine->code} — {$s->machine->name}" : null,
            ]);

        return Inertia::render('MES/Stations/Index', [
            'stations' => $stations,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/Stations/Create', [
            'workCenters' => $this->workCenterOptions(),
            'machines' => $this->machineOptions(),
        ]);
    }

    public function store(StoreStationRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.stations.index')->with('success', 'Station created.');
    }

    public function edit(Station $station): Response
    {
        return Inertia::render('MES/Stations/Edit', [
            'station' => $this->toFormData($station),
            'workCenters' => $this->workCenterOptions(),
            'machines' => $this->machineOptions(),
        ]);
    }

    public function update(UpdateStationRequest $request, Station $station)
    {
        $this->service->update($station, $request->validated());

        return redirect()->route('mes.stations.index')->with('success', 'Station updated.');
    }

    public function destroy(Station $station)
    {
        $this->service->delete($station);

        return redirect()->route('mes.stations.index')->with('success', 'Station deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Station::class, fn (Station $s) => $this->service->delete($s));
    }

    /** @return array<string, mixed> */
    private function toFormData(Station $station): array
    {
        return [
            'id' => $station->id,
            'work_center_id' => $station->work_center_id,
            'machine_id' => $station->machine_id,
            'code' => $station->code,
            'name' => $station->name,
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function machineOptions(): array
    {
        return Machine::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Machine $m) => ['value' => $m->id, 'label' => "{$m->code} — {$m->name}"])
            ->all();
    }
}
