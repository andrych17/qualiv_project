<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AsyncSearchRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsyncSearchController extends Controller
{
    /**
     * Handle flexible asynchronous search requests with a strict limit of 50 items.
     */
    public function index(Request $request): JsonResponse
    {
        $search = mb_strtoupper(trim((string) $request->input('q', '')), 'UTF-8');
        $entity = (string) $request->input('entity', 'user');
        $selectedId = $request->input('selected_id');
        $limit = min((int) $request->input('limit', 50), 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        // Hydrate single item if selected_id is provided
        if ($selectedId !== null) {
            $item = AsyncSearchRegistry::find($entity, $selectedId);

            return response()->json([
                'items' => $item ? [$item] : [],
                'total' => $item ? 1 : 0,
                'limit' => $limit,
            ]);
        }

        // Perform registry search across configured fields with max limit of 50
        $extraFilters = $request->except(['q', 'entity', 'limit', 'selected_id', 'page']);
        $result = AsyncSearchRegistry::search($entity, $search, $limit, $extraFilters);

        return response()->json([
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => $limit,
        ]);
    }
}
