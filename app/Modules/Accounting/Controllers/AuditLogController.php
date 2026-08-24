<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3O Audit Trail — same read-only, tenant-scoped-by-company shape as DMS's AuditLogController
 * (see that class's docblock); AuditLog enforces append-only itself, so there's no edit/delete
 * action to wire up here either.
 */
class AuditLogController extends Controller
{
    private const SORTABLE = ['created_at'];

    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $filters = $request->only('search', 'action', 'subject_type', 'actor_id', 'sort', 'direction', 'per_page');

        $logs = AuditLog::query()
            ->where('company_id', $companyId)
            ->with('actor:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'actor_name' => $log->actor?->name,
                'ip_address' => $log->ip_address,
                'before_snapshot' => $log->before_snapshot,
                'after_snapshot' => $log->after_snapshot,
                'created_at_formatted' => $log->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Accounting/AuditLog/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'logs' => $logs,
            'filters' => $filters,
            'actors' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::ACTIONS,
        ]);
    }
}
