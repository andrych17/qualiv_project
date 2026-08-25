<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Requests\StoreAdjustmentReasonRequest;
use App\Modules\Inventory\Requests\UpdateAdjustmentReasonRequest;
use App\Modules\Inventory\Services\AdjustmentReasonService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdjustmentReasonController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name'];

    public function __construct(protected AdjustmentReasonService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $reasons = AdjustmentReason::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (AdjustmentReason $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('Inventory/AdjustmentReasons/Index', [
            'reasons' => $reasons,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/AdjustmentReasons/Create');
    }

    public function store(StoreAdjustmentReasonRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.adjustmentReasons.index')->with('success', 'Reason created.');
    }

    public function edit(AdjustmentReason $adjustmentReason): Response
    {
        return Inertia::render('Inventory/AdjustmentReasons/Edit', [
            'reason' => $adjustmentReason->only('id', 'code', 'name', 'is_active'),
        ]);
    }

    public function update(UpdateAdjustmentReasonRequest $request, AdjustmentReason $adjustmentReason)
    {
        $this->service->update($adjustmentReason, $request->validated());

        return redirect()->route('inventory.adjustmentReasons.index')->with('success', 'Reason updated.');
    }

    public function destroy(AdjustmentReason $adjustmentReason)
    {
        $this->service->delete($adjustmentReason);

        return redirect()->route('inventory.adjustmentReasons.index')->with('success', 'Reason deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, AdjustmentReason::class, fn (AdjustmentReason $r) => $this->service->delete($r));
    }
}
