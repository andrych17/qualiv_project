<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\ShiftAssignment;
use App\Modules\MES\Models\ShiftHandoverNote;
use App\Modules\MES\Requests\StoreShiftHandoverNoteRequest;
use App\Modules\MES\Services\ShiftHandoverService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3P — Shift Reference & Handover. Create-only, no edit/delete (a handover note is a point-in-time record). */
class ShiftHandoverController extends Controller
{
    private const SORTABLE = ['created_at'];

    public function __construct(protected ShiftHandoverService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('work_date', 'shift_assignment_id', 'sort', 'direction', 'per_page');

        $notes = ShiftHandoverNote::query()
            ->with(['shiftAssignment.employee:id,full_name,employee_no', 'shiftAssignment.shift:id,name,start_time,end_time', 'createdBy:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (ShiftHandoverNote $note) => [
                'id' => $note->id,
                'employee_name' => $note->shiftAssignment?->employee?->full_name,
                'shift_name' => $note->shiftAssignment?->shift?->name,
                'work_date' => $note->shiftAssignment?->work_date?->toDateString(),
                'notes' => $note->notes,
                'order_summary' => $note->order_summary,
                'created_by_name' => $note->createdBy?->name,
                'created_at' => $note->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('MES/ShiftHandovers/Index', [
            'notes' => $notes,
            'filters' => $filters,
            'shiftAssignments' => $this->shiftAssignmentOptions(),
        ]);
    }

    public function store(StoreShiftHandoverNoteRequest $request)
    {
        $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('mes.shiftHandovers.index')->with('success', 'Handover note recorded.');
    }

    /** @return list<array{value: int, label: string}> */
    private function shiftAssignmentOptions(): array
    {
        return ShiftAssignment::query()
            ->with(['employee:id,full_name', 'shift:id,name'])
            ->whereDate('work_date', '>=', now()->subDays(2)->toDateString())
            ->orderByDesc('work_date')
            ->limit(100)
            ->get()
            ->map(fn (ShiftAssignment $a) => [
                'value' => $a->id,
                'label' => "{$a->work_date->toDateString()} — {$a->shift?->name} — {$a->employee?->full_name}",
            ])
            ->all();
    }
}
