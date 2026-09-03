<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Services\PosOfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS_SPECS.md §3S — Offline Transaction Sync Endpoint.
 */
class PosOfflineSyncController extends Controller
{
    public function __construct(
        protected PosOfflineSyncService $syncService,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terminal_id' => ['required', 'integer'],
            'transactions' => ['nullable', 'array'],
            'transaction' => ['nullable', 'array'],
        ]);

        $terminalId = (int) $validated['terminal_id'];
        $results = [];

        $payloads = $validated['transactions'] ?? ($validated['transaction'] ? [$validated['transaction']] : []);

        foreach ($payloads as $payload) {
            $results[] = $this->syncService->syncTransaction($payload, $terminalId);
        }

        return response()->json([
            'synced_count' => count($results),
            'results' => $results,
        ]);
    }
}
