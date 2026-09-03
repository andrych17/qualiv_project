<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreMachineRequest;
use App\Modules\MES\Requests\UpdateMachineRequest;
use App\Modules\MES\Services\MachineService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3D Equipment Master Data — Machines (Entry). */
class MachineController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name', 'status', 'created_at'];

    public function __construct(protected MachineService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'work_center_id', 'status', 'sort', 'direction', 'per_page');

        $machines = Machine::query()
            ->with('workCenter:id,code,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Machine $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'work_center_code' => $m->workCenter?->code,
                'work_center_name' => $m->workCenter?->name,
                'status' => $m->status,
            ]);

        return Inertia::render('MES/Machines/Index', [
            'machines' => $machines,
            'filters' => $filters,
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/Machines/Create', [
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function store(StoreMachineRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.machines.index')->with('success', 'Machine created.');
    }

    public function edit(Machine $machine): Response
    {
        return Inertia::render('MES/Machines/Edit', [
            'machine' => $this->toFormData($machine),
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function update(UpdateMachineRequest $request, Machine $machine)
    {
        $this->service->update($machine, $request->validated());

        return redirect()->route('mes.machines.index')->with('success', 'Machine updated.');
    }

    public function destroy(Machine $machine)
    {
        $this->service->delete($machine);

        return redirect()->route('mes.machines.index')->with('success', 'Machine deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Machine::class, fn (Machine $m) => $this->service->delete($m));
    }

    /** @return array<string, mixed> */
    private function toFormData(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'work_center_id' => $machine->work_center_id,
            'code' => $machine->code,
            'name' => $machine->name,
            'status' => $machine->status,
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
}
