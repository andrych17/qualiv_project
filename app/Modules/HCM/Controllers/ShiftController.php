<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Shift;
use App\Modules\HCM\Requests\StoreShiftRequest;
use App\Modules\HCM\Services\AttendanceService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    use BulkDeletable;

    public function __construct(
        protected AttendanceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'is_active', 'sort', 'direction', 'per_page');
        $perPage = TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15);
        $shifts = $this->service->paginateShifts($filters, $perPage);

        return Inertia::render('HCM/Shifts/Index', [
            'shifts' => $shifts,
            'filters' => $filters,
        ]);
    }

    public function store(StoreShiftRequest $request): RedirectResponse
    {
        $this->service->createShift($request->validated());

        return back()->with('success', 'Shift created successfully.');
    }

    public function update(StoreShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->service->updateShift($shift, $request->validated());

        return back()->with('success', 'Shift updated successfully.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $this->service->deleteShift($shift);

        return back()->with('success', 'Shift deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        return $this->bulkDestroyUsing($request, Shift::class, fn (Shift $s) => $this->service->deleteShift($s));
    }
}
