<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreDowntimeEventRequest;
use App\Modules\MES\Services\DowntimeService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3M — Equipment Status & Downtime. Start/end are the only write actions; no edit/delete, same append-only posture as the production event ledger this feeds. */
class DowntimeController extends Controller
{
    private const SORTABLE = ['started_at', 'ended_at', 'category'];

    public function __construct(protected DowntimeService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'category', 'machine_id', 'work_center_id', 'sort', 'direction', 'per_page');

        $events = DowntimeEvent::query()
            ->with(['machine:id,code,name', 'workCenter:id,code,name', 'order:id,order_number'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'started_at', 'desc'),
                fn ($query) => $query->orderByDesc('started_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (DowntimeEvent $d) => [
                'id' => $d->id,
                'machine_code' => $d->machine?->code,
                'work_center_code' => $d->workCenter?->code,
                'order_number' => $d->order?->order_number,
                'category' => $d->category,
                'reason_code' => $d->reason_code,
                'started_at' => $d->started_at?->toDateTimeString(),
                'ended_at' => $d->ended_at?->toDateTimeString(),
                'duration_minutes' => $d->started_at ? $d->started_at->diffInMinutes($d->ended_at ?? now()) : null,
                'is_open' => $d->ended_at === null,
            ]);

        return Inertia::render('MES/Downtime/Index', [
            'events' => $events,
            'filters' => $filters,
            'openCount' => DowntimeEvent::query()->open()->count(),
            'machines' => $this->machineOptions(),
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function store(StoreDowntimeEventRequest $request)
    {
        try {
            $this->service->start($request->validated(), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('mes.downtimeEvents.index')->with('success', 'Downtime logged.');
    }

    public function end(Request $request, DowntimeEvent $downtimeEvent)
    {
        try {
            $this->service->end($downtimeEvent, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Downtime ended.');
    }

    /** @return list<array{value: int, label: string}> */
    private function machineOptions(): array
    {
        return Machine::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Machine $m) => ['value' => $m->id, 'label' => "{$m->code} — {$m->name}"])
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }
}
