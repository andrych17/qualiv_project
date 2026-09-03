<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Services\PosSessionService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3C, §3D — POS Session Management Controller.
 */
class PosSessionController extends Controller
{
    public function __construct(
        protected PosSessionService $sessionService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'terminal_id', 'status', 'sort', 'direction', 'per_page');

        $sessions = PosSession::query()
            ->with(['terminal', 'cashier'])
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        $terminals = PosTerminal::query()->where('is_active', true)->get(['id', 'code', 'name']);

        return Inertia::render('POS/Sessions/Index', [
            'sessions' => $sessions,
            'terminals' => $terminals,
            'filters' => $filters,
        ]);
    }

    public function show(PosSession $session): JsonResponse
    {
        $summary = $this->sessionService->getSessionSummary($session->id);

        return response()->json($summary);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terminal_id' => ['required', 'integer'],
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $session = $this->sessionService->openSession(
            (int) $validated['terminal_id'],
            auth()->id() ?: 1,
            (float) ($validated['opening_cash'] ?? 0),
            $validated['employee_id'] ?? null
        );

        return response()->json($session->load('terminal'));
    }

    public function cashMovement(Request $request, PosSession $session): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:cash_in,cash_out,petty_cash'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string'],
        ]);

        $movement = $this->sessionService->addCashMovement(
            $session->id,
            $validated['type'],
            (float) $validated['amount'],
            $validated['reason'] ?? null,
            auth()->id() ?: 1
        );

        return response()->json($movement);
    }

    public function close(Request $request, PosSession $session): JsonResponse
    {
        $validated = $request->validate([
            'actual_cash' => ['required', 'numeric'],
            'supervisor_pin' => ['nullable', 'string'],
        ]);

        $closedSession = $this->sessionService->closeSession(
            $session->id,
            (float) $validated['actual_cash'],
            auth()->id() ?: 1,
            $validated['supervisor_pin'] ?? null
        );

        return response()->json($closedSession);
    }
}
