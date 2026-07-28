<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Legal\Models\LegalCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsyncSearchController extends Controller
{
    /**
     * Handle asynchronous search requests with a strict limit of 50 items.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $entity = (string) $request->input('entity', 'user');
        $selectedId = $request->input('selected_id');
        $limit = min((int) $request->input('limit', 50), 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        // If selected_id is passed, hydrate single item
        if ($selectedId !== null) {
            $item = $this->fetchSingleItem($entity, $selectedId);

            return response()->json([
                'items' => $item ? [$item] : [],
                'total' => $item ? 1 : 0,
                'limit' => $limit,
            ]);
        }

        // Perform async query with max limit of 50
        $result = match ($entity) {
            'legal_case', 'cases' => $this->searchCases($search, $limit),
            default => $this->searchUsers($search, $limit),
        };

        return response()->json([
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => $limit,
        ]);
    }

    private function searchUsers(string $search, int $limit): array
    {
        $query = User::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $total = $query->count();

        $items = $query->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'value' => $user->id,
                'label' => $user->name,
                'description' => $user->email,
                'avatar' => strtoupper(substr($user->name, 0, 1)),
                'badge' => 'User',
            ]);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    private function searchCases(string $search, int $limit): array
    {
        $query = LegalCase::query();

        if ($search !== '') {
            $query->filter(['search' => $search]);
        }

        $total = $query->count();

        $items = $query->limit($limit)
            ->get()
            ->map(fn (LegalCase $case) => [
                'value' => $case->id,
                'label' => "{$case->code} — {$case->title}",
                'description' => "Status: {$case->status}",
                'badge' => $case->status,
            ]);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    private function fetchSingleItem(string $entity, mixed $id): ?array
    {
        return match ($entity) {
            'legal_case', 'cases' => LegalCase::where('id', $id)->first()?->toArray() ? [
                'value' => $id,
                'label' => LegalCase::find($id)?->code.' — '.LegalCase::find($id)?->title,
                'description' => 'Status: '.LegalCase::find($id)?->status,
                'badge' => LegalCase::find($id)?->status,
            ] : null,
            default => User::find($id) ? [
                'value' => $id,
                'label' => User::find($id)->name,
                'description' => User::find($id)->email,
                'avatar' => strtoupper(substr(User::find($id)->name, 0, 1)),
                'badge' => 'User',
            ] : null,
        };
    }
}
