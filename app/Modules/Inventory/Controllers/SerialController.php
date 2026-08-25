<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockSerial;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3M Serial Number Tracking (read-only browse). Unlike Batches, there's no "pre-register
 * before stock exists" screen — a serial doesn't exist until a Goods Receipt creates it
 * (SerialService::receive()), so this is a lookup, not master-data CRUD.
 */
class SerialController extends Controller
{
    private const SORTABLE = ['serial_number', 'created_at'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'product_id', 'status', 'sort', 'direction', 'per_page');

        $serials = StockSerial::query()
            ->with(['product:id,sku,name', 'warehouse:id,name', 'location:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('serial_number', 'ilike', "%{$s}%"))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (StockSerial $s) => [
                'id' => $s->id,
                'serial_number' => $s->serial_number,
                'product_sku' => $s->product?->sku,
                'product_name' => $s->product?->name,
                'status' => $s->status,
                'warehouse_name' => $s->warehouse?->name,
                'location_code' => $s->location?->code,
            ]);

        return Inertia::render('Inventory/Serials/Index', [
            'serials' => $serials,
            'filters' => $filters,
            'products' => Product::query()->where('is_active', true)->where('tracking_mode', Product::TRACKING_SERIAL)->orderBy('sku')->get(['id', 'sku', 'name']),
        ]);
    }
}
