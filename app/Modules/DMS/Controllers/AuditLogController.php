<?php

namespace App\Modules\DMS\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\DMS\Models\AccessLog;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3I Audit Trail — tenant-wide view across every document, not just the §3A drawer's
 * per-document (capped) Audit Log tab. Read-only: AccessLog enforces append-only itself
 * (see the model), so there's no edit/delete action to wire up here even if we wanted one.
 */
class AuditLogController extends Controller
{
    private const SORTABLE = ['created_at'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'action', 'document_id', 'actor_id', 'sort', 'direction', 'per_page');

        $logs = AccessLog::query()
            ->with(['document:id,title', 'actor:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (AccessLog $log) => [
                'id' => $log->id,
                'document_id' => $log->document_id,
                'document_title' => $log->document?->title,
                'action' => $log->action,
                'actor_name' => $log->actor?->name,
                'ip_address' => $log->ip_address,
                'created_at_formatted' => $log->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('DMS/AuditLog/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actors' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => AccessLog::ACTIONS,
        ]);
    }
}
