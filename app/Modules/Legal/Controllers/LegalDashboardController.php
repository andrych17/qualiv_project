<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DMS\Models\Document;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A: Legal's own landing page — practice-health summary cards + a unified "my work" queue
 * across Matters/Deeds/Field Visits/Protocol Books, mirroring CRM's dashboard
 * (`CrmDashboardController`, CRM_SPECS.md §3A) that this spec explicitly says to unify with.
 * Ships last, once §3B-§3M existed to aggregate — same reasoning CRM's own dashboard docblock
 * gives for shipping last.
 */
class LegalDashboardController extends Controller
{
    private const CAP = 25;

    public function index(): Response
    {
        $userId = Auth::id();
        $graceDays = (int) (app(ConfigService::class)->get('LEGAL', 'DPW_GRACE_DAYS', 'LEGAL') ?? 14);

        $myMatters = Matter::query()
            ->where('assigned_to', $userId)
            ->where('status', '!=', Matter::STATUS_CLOSED)
            ->with('partner:id,name')
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get();

        $myDeeds = Deed::query()
            ->whereHas('matter', fn ($q) => $q->where('assigned_to', $userId))
            ->where('status', '!=', Deed::STATUS_ARCHIVED)
            ->with(['deedType:id,name,category', 'matter:id,code', 'will', 'taxes:id,deed_id,status'])
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get();

        $myFieldVisits = FieldVisit::query()
            ->where('assigned_to', $userId)
            ->where('status', '!=', FieldVisit::STATUS_COMPLETED)
            ->with(['visitType:id,name', 'matter:id,code'])
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get();

        // Not per-assignee — a firm's protocol books are few and shared across notaries, same
        // "small firm-wide list, not a per-user queue" shape as CRM's "Recent Partners" tab.
        $protocolBooks = ProtocolBook::query()
            ->where('status', ProtocolBook::STATUS_ACTIVE)
            ->orderBy('book_type')
            ->orderByDesc('year')
            ->limit(self::CAP)
            ->get();

        return Inertia::render('Legal/Dashboard/Index', [
            'summary' => [
                'open_matters' => Matter::query()->where('status', '!=', Matter::STATUS_CLOSED)->count(),
                'deeds_pending_signature' => Deed::query()->where('status', Deed::STATUS_READY_FOR_SIGNING)->count(),
                'tax_pending_clearance' => DeedTax::query()->where('status', '!=', DeedTax::STATUS_VALIDATED)->count(),
                'bpn_in_process' => BpnSubmission::query()
                    ->whereNotIn('status', [BpnSubmission::STATUS_COMPLETED, BpnSubmission::STATUS_REJECTED])
                    ->count(),
            ],
            'myMatters' => $myMatters->map(fn (Matter $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'title' => $m->title,
                'partner_name' => $m->partner?->name,
                'status' => $m->status,
            ]),
            'myDeeds' => $myDeeds->map(function (Deed $d) use ($graceDays) {
                $taxPending = $d->category === 'ppat' && $d->taxes->contains(fn (DeedTax $t) => $t->status !== DeedTax::STATUS_VALIDATED);
                $dpwOverdue = $d->will?->isOverdueForDpw($graceDays) ?? false;

                return [
                    'id' => $d->id,
                    'deed_number' => $d->deed_number,
                    'deed_type_name' => $d->deedType?->name,
                    'matter_code' => $d->matter?->code,
                    'category' => $d->category,
                    'status' => $d->status,
                    // §3A: "A PPAT deed with unpaid/unvalidated tax surfaces with a danger rail
                    // regardless of sort" — the DPW-overdue will is the same class of risk.
                    'danger' => $taxPending || $dpwOverdue,
                    'danger_reason' => $taxPending ? 'Tax not yet cleared' : ($dpwOverdue ? 'Will overdue for DPW' : null),
                ];
            }),
            'myFieldVisits' => $myFieldVisits->map(fn (FieldVisit $v) => [
                'id' => $v->id,
                'visit_type_name' => $v->visitType?->name,
                'matter_code' => $v->matter?->code,
                'status' => $v->status,
            ]),
            'protocolBooks' => $protocolBooks->map(fn (ProtocolBook $b) => [
                'id' => $b->id,
                'label' => "{$b->abbreviation()}/{$b->year} vol. {$b->volume}",
                'book_type' => $b->book_type,
                'year' => $b->year,
                'status' => $b->status,
            ]),
        ]);
    }

    public function matterDrawer(Matter $matter)
    {
        $matter->loadMissing('partner:id,name', 'assignee:id,name');

        return response()->json([
            'type' => 'matter',
            'record' => [
                'id' => $matter->id,
                'code' => $matter->code,
                'title' => $matter->title,
                'matter_type' => $matter->matter_type,
                'partner_name' => $matter->partner?->name,
                'assignee_name' => $matter->assignee?->name,
                'status' => $matter->status,
                'opened_at_formatted' => $matter->opened_at?->format('d M Y'),
                'target_close_at_formatted' => $matter->target_close_at?->format('d M Y'),
                'notes' => $matter->notes,
                'deed_count' => $matter->deeds()->count(),
                'edit_url' => route('legal.matters.edit', $matter->id),
            ],
            'documents' => $this->linkedDocuments('legal.matters', $matter->id),
        ]);
    }

    public function deedDrawer(Deed $deed)
    {
        $deed->loadMissing('deedType:id,name,category', 'matter:id,code,title', 'taxes.taxpayer:id,name');

        return response()->json([
            'type' => 'deed',
            'record' => [
                'id' => $deed->id,
                'deed_number' => $deed->deed_number,
                'deed_type_name' => $deed->deedType?->name,
                'matter_code' => $deed->matter?->code,
                'matter_title' => $deed->matter?->title,
                'category' => $deed->category,
                'status' => $deed->status,
                'signing_date_formatted' => $deed->signing_date?->format('d M Y'),
                'summary' => $deed->summary,
                'edit_url' => route('legal.deeds.edit', $deed->id),
            ],
            'taxes' => $deed->taxes->map(fn (DeedTax $t) => [
                'id' => $t->id,
                'tax_type' => $t->tax_type,
                'taxpayer_name' => $t->taxpayer?->name,
                'computed_amount' => $t->computed_amount,
                'status' => $t->status,
            ]),
            'documents' => $this->linkedDocuments('legal.deeds', $deed->id),
        ]);
    }

    public function fieldVisitDrawer(FieldVisit $fieldVisit)
    {
        $fieldVisit->loadMissing('visitType:id,name', 'matter:id,code,title', 'assignee:id,name');

        return response()->json([
            'type' => 'fieldVisit',
            'record' => [
                'id' => $fieldVisit->id,
                'visit_type_name' => $fieldVisit->visitType?->name,
                'matter_code' => $fieldVisit->matter?->code,
                'matter_title' => $fieldVisit->matter?->title,
                'assignee_name' => $fieldVisit->assignee?->name,
                'status' => $fieldVisit->status,
                'checked_in_at_formatted' => $fieldVisit->checked_in_at?->format('d M Y H:i'),
                'notes' => $fieldVisit->notes,
                'edit_url' => route('legal.fieldVisits.edit', $fieldVisit->id),
            ],
            'documents' => $this->linkedDocuments('legal.field_visits', $fieldVisit->id),
        ]);
    }

    public function protocolBookDrawer(ProtocolBook $protocolBook)
    {
        $protocolBook->loadMissing('notary:id,name');

        return response()->json([
            'type' => 'protocolBook',
            'record' => [
                'id' => $protocolBook->id,
                'label' => "{$protocolBook->abbreviation()}/{$protocolBook->year} vol. {$protocolBook->volume}",
                'book_type' => $protocolBook->book_type,
                'notary_name' => $protocolBook->notary?->name,
                'status' => $protocolBook->status,
                'opened_at_formatted' => $protocolBook->opened_at?->format('d M Y'),
                'edit_url' => route('legal.protocolBooks.manifest', $protocolBook->id),
            ],
            'entries' => $protocolBook->entries()->with('deed:id,deed_number')->limit(10)->get()->map(fn ($e) => [
                'id' => $e->id,
                'sequence_number' => $e->sequence_number,
                'entry_date_formatted' => $e->entry_date?->format('d M Y'),
                'deed_number' => $e->deed?->deed_number,
            ]),
        ]);
    }

    /**
     * LEGAL_SPECS.md §5: "A matter, deed, or field visit's documents are found by querying DMS
     * for subject_type = 'legal.matters' / 'legal.deeds' / 'legal.field_visits'" — this substitutes
     * for §3B's own "Activity Timeline" detail-view tab, which (like Legal's audit-log story
     * generally) isn't built anywhere in this module yet, same "built what exists today" call
     * CrmDashboardController's own partnerDrawer docblock makes for its cross-reference counts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkedDocuments(string $subjectType, int $subjectId): array
    {
        return Document::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNotIn('status', [Document::STATUS_PURGED])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'title', 'status'])
            ->map(fn (Document $d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])
            ->all();
    }
}
