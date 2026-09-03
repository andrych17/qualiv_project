<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\MesAuditLog;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3U Digital Audit Trail — read-only, system-written only (see MesAuditLogger). No edit/delete: append-only. */
class MesAuditLogController extends Controller
{
    private const SORTABLE = ['created_at'];

    public function index(Request $request): Response
    {
        $filters = $request->only('subject_type', 'action', 'actor_id', 'sort', 'direction', 'per_page');

        $logs = MesAuditLog::query()
            ->with('actor:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (MesAuditLog $log) => [
                'id' => $log->id,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'action' => $log->action,
                'actor_name' => $log->actor?->name,
                'before_snapshot' => $log->before_snapshot,
                'after_snapshot' => $log->after_snapshot,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('MES/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
        ]);
    }
}
