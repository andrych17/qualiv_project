<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProdEvent;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MES_SPECS.md §3C Production Event Ledger — read-only. The ledger is written exclusively via
 * `ProdEventService::record()` from within each execution engine (§3A's release action today;
 * §3G–§3M once built), never through this controller — there is no create/edit/delete route
 * here on purpose (§3C: "immutable... corrections are new events").
 */
class ProdEventController extends Controller
{
    private const SORTABLE = ['occurred_at'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'order_id', 'event_type', 'sort', 'direction', 'per_page');

        $events = ProdEvent::query()
            ->with(['order:id,order_number', 'user:id,name', 'machine:id,code'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'occurred_at', 'desc'),
                fn ($query) => $query->orderByDesc('occurred_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 30))
            ->withQueryString()
            ->through(fn (ProdEvent $e) => [
                'id' => $e->id,
                'order_id' => $e->order_id,
                'order_number' => $e->order?->order_number,
                'event_type' => $e->event_type,
                'payload' => $e->payload,
                'occurred_at' => $e->occurred_at?->toDateTimeString(),
                'user_name' => $e->user?->name,
                'machine_code' => $e->machine?->code,
            ]);

        return Inertia::render('MES/ProdEvents/Index', [
            'events' => $events,
            'filters' => $filters,
        ]);
    }
}
