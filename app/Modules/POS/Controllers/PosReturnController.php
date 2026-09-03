<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosReturnHdr;
use App\Modules\POS\Services\PosReturnService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3L — POS Returns & Refunds Controller.
 */
class PosReturnController extends Controller
{
    public function __construct(
        protected PosReturnService $returnService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $returns = PosReturnHdr::query()
            ->with(['originalTransaction', 'session.terminal', 'lines'])
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('POS/Returns/Index', [
            'returns' => $returns,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_txn_id' => ['required', 'integer'],
            'session_id' => ['required', 'integer'],
            'reason_code' => ['required', 'string', 'max:30'],
            'refund_method' => ['nullable', 'string'],
            'without_receipt' => ['nullable', 'boolean'],
            'supervisor_pin' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.original_txn_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.condition_note' => ['nullable', 'string'],
            'lines.*.restockable' => ['nullable', 'boolean'],
        ]);

        $return = $this->returnService->processReturn(
            (int) $validated['original_txn_id'],
            (int) $validated['session_id'],
            $validated['reason_code'],
            $validated['lines'],
            $validated['refund_method'] ?? 'cash',
            (bool) ($validated['without_receipt'] ?? false),
            $validated['supervisor_pin'] ?? null,
            auth()->id() ?: 1
        );

        return response()->json($return, 201);
    }
}
