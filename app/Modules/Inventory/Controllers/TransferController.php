<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreTransferRequest;
use App\Modules\Inventory\Requests\UpdateTransferRequest;
use App\Modules\Inventory\Services\TransferService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3F Transfers (Entry). */
class TransferController extends Controller
{
    private const SORTABLE = ['transfer_date', 'created_at'];

    public function __construct(protected TransferService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'sort', 'direction', 'per_page');

        $transfers = Transfer::query()
            ->with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name'])
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Transfer $t) => [
                'id' => $t->id,
                'source_warehouse_name' => $t->sourceWarehouse?->name,
                'destination_warehouse_name' => $t->destinationWarehouse?->name,
                'transfer_date_formatted' => $t->transfer_date?->format('d M Y'),
                'line_count' => $t->lines_count,
                'status' => $t->status,
            ]);

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => $transfers,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Transfers/Create', $this->formProps());
    }

    public function store(StoreTransferRequest $request)
    {
        $transfer = $this->service->create($request->validated());

        return redirect()->route('inventory.transfers.edit', $transfer)->with('success', 'Transfer saved as draft.');
    }

    public function edit(Transfer $transfer): Response
    {
        return Inertia::render('Inventory/Transfers/Edit', [
            ...$this->formProps(),
            'transfer' => $this->toFormData($transfer),
        ]);
    }

    public function update(UpdateTransferRequest $request, Transfer $transfer)
    {
        $this->service->update($transfer, $request->validated());

        return redirect()->route('inventory.transfers.edit', $transfer)->with('success', 'Transfer updated.');
    }

    public function destroy(Transfer $transfer)
    {
        $this->service->delete($transfer);

        return redirect()->route('inventory.transfers.index')->with('success', 'Transfer deleted.');
    }

    public function post(Transfer $transfer)
    {
        $this->service->post($transfer);

        return redirect()->route('inventory.transfers.edit', $transfer)->with('success', 'Transfer posted.');
    }

    public function complete(Transfer $transfer)
    {
        $this->service->complete($transfer);

        return redirect()->route('inventory.transfers.edit', $transfer)->with('success', 'Transfer marked completed.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'uoms' => Uom::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'productTracking' => Product::query()->where('is_active', true)->pluck('tracking_mode', 'id'),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Transfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'source_warehouse_id' => $transfer->source_warehouse_id,
            'source_location_id' => $transfer->source_location_id,
            'destination_warehouse_id' => $transfer->destination_warehouse_id,
            'destination_location_id' => $transfer->destination_location_id,
            'transfer_date' => $transfer->transfer_date->toDateString(),
            'status' => $transfer->status,
            'lines' => $transfer->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'qty' => (float) $l->qty,
                'uom_id' => $l->uom_id,
                'batch_id' => $l->batch_id,
                'batch_label' => $l->batch?->batch_number,
                'serial_numbers' => $l->serial_numbers ?? [],
            ]),
        ];
    }
}
