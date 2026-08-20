<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\Ticket;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A: CRM's own landing page — summary cards + a unified "my work" queue across
 * Leads/Tickets/Service Cases, aggregating everything §3B-§3G built. Ships last
 * per CRM_SPECS.md's own suggested build order.
 */
class CrmDashboardController extends Controller
{
    private const CAP = 25;

    public function index(): Response
    {
        $userId = Auth::id();

        $myLeads = Lead::query()
            ->where('owner_id', $userId)
            ->whereNotIn('stage', [Lead::STAGE_CONVERTED, Lead::STAGE_DISQUALIFIED])
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get();

        $myTickets = $this->slaFirst(
            Ticket::query()
                ->where('assigned_to', $userId)
                ->whereNotIn('status', Ticket::CLOSED_STATUSES)
                ->with('partner:id,name')
                ->orderByDesc('id')
                ->limit(self::CAP)
                ->get()
        );

        $myServiceCases = $this->slaFirst(
            ServiceCase::query()
                ->where('assigned_to', $userId)
                ->whereNotIn('status', ServiceCase::CLOSED_STATUSES)
                ->with('partner:id,name')
                ->orderByDesc('id')
                ->limit(self::CAP)
                ->get()
        );

        $recentPartners = Partner::query()
            ->where('is_active', true)
            ->whereNull('merged_into_partner_id')
            ->orderByDesc('created_at')
            ->limit(self::CAP)
            ->get(['id', 'uuid', 'type', 'name', 'created_at']);

        $permissions = app(ConfigService::class)->permissionsForUserMenu((int) $userId, 'CRM');

        return Inertia::render('CRM/Dashboard/Index', [
            'summary' => [
                'open_leads' => Lead::query()->whereNotIn('stage', [Lead::STAGE_CONVERTED, Lead::STAGE_DISQUALIFIED])->count(),
                'open_tickets' => Ticket::query()->whereNotIn('status', Ticket::CLOSED_STATUSES)->count(),
                'open_service_cases' => ServiceCase::query()->whereNotIn('status', ServiceCase::CLOSED_STATUSES)->count(),
                // Live-state count, consistent with the other three cards — a tombstoned
                // duplicate merged away by §3G doesn't count as a partner "added."
                'partners_added_30d' => Partner::query()
                    ->where('is_active', true)
                    ->whereNull('merged_into_partner_id')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'myLeads' => $myLeads->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'company_name' => $l->company_name,
                'stage' => $l->stage,
                'next_action_formatted' => $l->next_action_at?->format('d M Y'),
            ]),
            'myTickets' => $myTickets->map(fn (Ticket $t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'requester_name' => $t->requesterLabel(),
                'status' => $t->status,
                'sla_state' => $t->sla_state,
            ]),
            'myServiceCases' => $myServiceCases->map(fn (ServiceCase $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'partner_name' => $c->partner?->name,
                'status' => $c->status,
                'sla_state' => $c->sla_state,
            ]),
            'recentPartners' => $recentPartners->map(fn (Partner $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'created_at_formatted' => $p->created_at?->format('d M Y'),
            ]),
            // Drawer's quick status-change controls hit an 'update'-gated endpoint —
            // VIEWER only holds 'R' on CRM, so hide the control rather than let it 403.
            'canUpdate' => (bool) ($permissions['update'] ?? false),
        ]);
    }

    public function leadDrawer(Lead $lead)
    {
        return response()->json([
            'type' => 'lead',
            'record' => [
                'id' => $lead->id,
                'name' => $lead->name,
                'company_name' => $lead->company_name,
                'stage' => $lead->stage,
                'estimated_value' => $lead->estimated_value,
                'notes' => $lead->notes,
                'edit_url' => route('crm.leads.edit', $lead->id),
            ],
            'activities' => $lead->activities()->with('loggedBy:id,name')->limit(10)->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->activity_type,
                'body' => $a->body,
                'by' => $a->loggedBy?->name,
                'at' => $a->logged_at?->format('d M Y H:i'),
            ]),
        ]);
    }

    public function ticketDrawer(Ticket $ticket)
    {
        return response()->json([
            'type' => 'ticket',
            'record' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'requester_name' => $ticket->requesterLabel(),
                'status' => $ticket->status,
                'sla_state' => $ticket->sla_state,
                'edit_url' => route('crm.tickets.edit', $ticket->id),
            ],
            'activities' => $ticket->messages()->with('sender:id,name')->limit(10)->get()->map(fn ($m) => [
                'id' => $m->id,
                'label' => $m->direction,
                'body' => $m->body,
                'by' => $m->sender?->name ?? $m->sender_name,
                'at' => $m->sent_at?->format('d M Y H:i'),
            ]),
        ]);
    }

    public function caseDrawer(ServiceCase $case)
    {
        return response()->json([
            'type' => 'case',
            'record' => [
                'id' => $case->id,
                'subject' => $case->subject,
                'partner_name' => $case->partner?->name,
                'status' => $case->status,
                'sla_state' => $case->sla_state,
                'edit_url' => route('crm.serviceCases.edit', $case->id),
            ],
            'activities' => $case->activities()->with('loggedBy:id,name')->limit(10)->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->activity_type,
                'body' => $a->body,
                'by' => $a->loggedBy?->name,
                'at' => $a->logged_at?->format('d M Y H:i'),
            ]),
        ]);
    }

    /**
     * §3B/§3C's own Activity Timeline tab was never built (deferred, see the CRM build
     * summary) — this drawer substitutes cross-engine reference counts, which is what's
     * actually available today, rather than a merged timeline that doesn't exist yet.
     */
    public function partnerDrawer(Partner $partner)
    {
        return response()->json([
            'type' => 'partner',
            'record' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'type' => $partner->type,
                'edit_url' => $partner->type === Partner::TYPE_ORGANIZATION
                    ? route('crm.companies.edit', $partner->id)
                    : route('crm.contacts.edit', $partner->id),
            ],
            'references' => [
                'leads' => Lead::query()->where('converted_partner_id', $partner->id)->count(),
                'service_cases' => ServiceCase::query()->where('partner_id', $partner->id)->count(),
                'tickets' => Ticket::query()->where('partner_id', $partner->id)->count(),
                'roles' => $partner->roles()->count(),
            ],
        ]);
    }

    /** PHP sort on the HasSlaState accessor — same definition the SQL filter uses, no third copy of the breached/due-soon boundary. */
    private function slaFirst(Collection $rows): Collection
    {
        $rank = ['breached' => 0, 'due_soon' => 1, 'on_track' => 2];

        return $rows->sortBy(fn ($row) => $rank[$row->sla_state] ?? 3)->values();
    }
}
