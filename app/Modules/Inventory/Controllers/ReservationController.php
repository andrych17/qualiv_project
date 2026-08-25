<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\ReservationService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3N Reservations (read-only browse + manual release). No create form — a reservation is
 * meant to be made by a caller via `InventoryService::reserve()` (Sales order-confirm, not
 * built yet — see InventoryService's docblock), same posture as Serials (§3M). Manual release
 * is a real operational need though: an order cancelled outside the system still needs its
 * hold freed without waiting for the expiry sweep.
 */
class ReservationController extends Controller
{
    private const SORTABLE = ['created_at', 'expires_at', 'qty'];

    public function __construct(protected ReservationService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('product_id', 'status', 'sort', 'direction', 'per_page');

        $reservations = StockReservation::query()
            ->with(['product:id,sku,name', 'warehouse:id,name', 'location:id,code', 'batch:id,batch_number', 'serial:id,serial_number'])
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (StockReservation $r) => [
                'id' => $r->id,
                'product_sku' => $r->product?->sku,
                'product_name' => $r->product?->name,
                'qty' => (float) $r->qty,
                'warehouse_name' => $r->warehouse?->name,
                'location_code' => $r->location?->code,
                'batch_number' => $r->batch?->batch_number,
                'serial_number' => $r->serial?->serial_number,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'status' => $r->status,
                // §3N: an 'active' row past its expiry is effectively released already (ATP
                // ignores it live) even if the hourly sweep hasn't flipped it yet — surface
                // that rather than showing a misleadingly plain "Active".
                'is_expired' => $r->status === StockReservation::STATUS_ACTIVE && $r->isExpired(),
                'expires_at_formatted' => $r->expires_at?->format('d M Y H:i'),
                'created_at_formatted' => $r->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Inventory/Reservations/Index', [
            'reservations' => $reservations,
            'filters' => $filters,
            'products' => Product::query()->where('is_active', true)->orderBy('sku')->get(['id', 'sku', 'name']),
        ]);
    }

    public function release(StockReservation $reservation)
    {
        $this->service->release($reservation);

        return redirect()->route('inventory.reservations.index')->with('success', 'Reservation released.');
    }
}
