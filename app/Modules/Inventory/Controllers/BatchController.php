<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Requests\StoreBatchRequest;
use App\Modules\Inventory\Requests\UpdateBatchRequest;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\SysConfig\Services\ConfigService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3L Batch / Lot Tracking (Master data). Most batches are created implicitly by a Goods
 * Receipt line's free-text lot number (BatchService::resolve()); this screen is for
 * pre-registering a lot before stock exists, correcting its expiry/manufacture dates, and —
 * the piece of §3A's "expiring-soon batches" Dashboard requirement that doesn't need an
 * actual Dashboard page to exist yet — surfacing expiring/expired lots with a Status Rail.
 */
class BatchController extends Controller
{
    private const SORTABLE = ['batch_number', 'expiry_date', 'created_at'];

    public function __construct(
        protected BatchService $service,
        protected ConfigService $config,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'product_id', 'expiry_status', 'sort', 'direction', 'per_page');
        $warningDays = (int) ($this->config->get('INVENTORY', 'BATCH_EXPIRY_WARNING_DAYS') ?? 30);
        $warningDate = now()->addDays($warningDays)->toDateString();
        $today = now()->toDateString();

        $batches = StockBatch::query()
            ->with('product:id,sku,name')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('batch_number', 'ilike', "%{$s}%"))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when(($filters['expiry_status'] ?? null) === 'expired', fn ($q) => $q->whereNotNull('expiry_date')->where('expiry_date', '<', $today))
            ->when(($filters['expiry_status'] ?? null) === 'expiring_soon', fn ($q) => $q->whereNotNull('expiry_date')->whereBetween('expiry_date', [$today, $warningDate]))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'expiry_date', 'asc'),
                fn ($query) => $query->orderByRaw('expiry_date IS NULL, expiry_date ASC'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (StockBatch $b) => [
                'id' => $b->id,
                'batch_number' => $b->batch_number,
                'product_sku' => $b->product?->sku,
                'product_name' => $b->product?->name,
                'expiry_date_formatted' => $b->expiry_date?->format('d M Y'),
                'manufacture_date_formatted' => $b->manufacture_date?->format('d M Y'),
                'supplier_reference' => $b->supplier_reference,
                'status_rail' => $this->statusRail($b, $today, $warningDate),
            ]);

        return Inertia::render('Inventory/Batches/Index', [
            'batches' => $batches,
            'filters' => $filters,
            'products' => Product::query()->where('is_active', true)->where('tracking_mode', Product::TRACKING_BATCH)->orderBy('sku')->get(['id', 'sku', 'name']),
            'warningDays' => $warningDays,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Batches/Create');
    }

    public function store(StoreBatchRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('inventory.batches.index')->with('success', 'Batch created.');
    }

    public function edit(StockBatch $batch): Response
    {
        return Inertia::render('Inventory/Batches/Edit', [
            'batch' => [
                ...$batch->only('id', 'batch_number', 'supplier_reference'),
                'product_sku' => $batch->product?->sku,
                'product_name' => $batch->product?->name,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'manufacture_date' => $batch->manufacture_date?->toDateString(),
            ],
        ]);
    }

    public function update(UpdateBatchRequest $request, StockBatch $batch)
    {
        $this->service->update($batch, $request->validated());

        return redirect()->route('inventory.batches.index')->with('success', 'Batch updated.');
    }

    /** Blocked once any ledger entry references it — a batch with movement history is no longer just draft master data. */
    public function destroy(StockBatch $batch)
    {
        if (StockLedger::query()->where('batch_id', $batch->id)->exists()) {
            throw ValidationException::withMessages(['batch_number' => 'This batch already has stock movement history and can\'t be deleted.']);
        }

        $batch->delete();

        return redirect()->route('inventory.batches.index')->with('success', 'Batch deleted.');
    }

    private function statusRail(StockBatch $batch, string $today, string $warningDate): string
    {
        if (! $batch->expiry_date) {
            return '';
        }

        $expiry = $batch->expiry_date->toDateString();
        if ($expiry < $today) {
            return 'danger';
        }
        if ($expiry <= $warningDate) {
            return 'warning';
        }

        return 'success';
    }
}
