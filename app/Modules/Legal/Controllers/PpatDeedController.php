<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Models\PartyRoleType;
use App\Modules\Legal\Requests\StorePpatDeedRequest;
use App\Modules\Legal\Requests\UpdatePpatDeedRequest;
use App\Modules\Legal\Services\DeedService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3G — AJB, Hibah & other PPAT deeds. Same LEGAL.deeds table and DeedService as §3C's
 * DeedController (unified Deed model, LEGAL_SPECS.md §3G intro) — a separate controller
 * because the entry fields and gate differ (land_object_id/transaction_value, due-diligence
 * gate), mirroring how CRM splits Contact/Company over the same Partner model.
 */
class PpatDeedController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['deed_number', 'status', 'signing_date', 'created_at'];

    public function __construct(
        protected DeedService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $deeds = Deed::query()
            ->ppat()
            ->with(['deedType:id,name', 'matter:id,code,title', 'landObject:id,certificate_number'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Deed $d) => [
                'id' => $d->id,
                'deed_number' => $d->deed_number,
                'deed_type_name' => $d->deedType?->name,
                'matter_code' => $d->matter?->code,
                'land_object_certificate' => $d->landObject?->certificate_number,
                'transaction_value' => $d->transaction_value,
                'status' => $d->status,
                'signing_date_formatted' => $d->signing_date?->format('d M Y'),
            ]);

        return Inertia::render('Legal/PpatDeeds/Index', [
            'deeds' => $deeds,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/PpatDeeds/Create', [
            'deedTypes' => DeedType::query()->where('category', DeedType::CATEGORY_PPAT)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::query()->orderByDesc('id')->limit(200)->get(['id', 'code', 'title']),
            'landObjects' => LandObject::query()->orderByDesc('id')->limit(200)->get(['id', 'certificate_number', 'address']),
            'customFields' => $this->customFields->formPayload(DeedService::ENTITY),
        ]);
    }

    public function store(StorePpatDeedRequest $request)
    {
        $deed = $this->service->create($request->validated());

        return redirect()->route('legal.ppatDeeds.edit', $deed)
            ->with('success', 'PPAT deed drafted.');
    }

    public function edit(Deed $deed): Response
    {
        return Inertia::render('Legal/PpatDeeds/Edit', [
            'deed' => [
                'id' => $deed->id,
                'matter_id' => $deed->matter_id,
                'deed_type_id' => $deed->deed_type_id,
                'land_object_id' => $deed->land_object_id,
                'transaction_value' => $deed->transaction_value,
                'deed_number' => $deed->deed_number,
                'status' => $deed->status,
                'signing_date' => $deed->signing_date?->toDateString(),
                'minuta_reference' => $deed->minuta_reference,
                'summary' => $deed->summary,
                'is_locked' => $deed->isLocked(),
                'next_statuses' => Deed::TRANSITIONS[$deed->status] ?? [],
            ],
            'deedTypes' => DeedType::query()->where('category', DeedType::CATEGORY_PPAT)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::query()->orderByDesc('id')->limit(200)->get(['id', 'code', 'title']),
            'landObjects' => LandObject::query()->orderByDesc('id')->limit(200)->get(['id', 'certificate_number', 'address']),
            'customFields' => $this->customFields->formPayload(DeedService::ENTITY, $deed->id),
            'partyRoleTypes' => PartyRoleType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'parties' => $deed->parties()->with('partner:id,name')->orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->id,
                'partner_id' => $p->partner_id,
                'partner_name' => $p->partner?->name,
                'role_type_id' => $p->role_type_id,
                'identity_name' => $p->identity_snapshot['name'] ?? null,
                'identity_id_number' => $p->identity_snapshot['id_number'] ?? null,
                'identity_address' => $p->identity_snapshot['address'] ?? null,
            ]),
            'taxes' => $deed->taxes()->get()->map(fn (DeedTax $t) => [
                'id' => $t->id,
                'tax_type' => $t->tax_type,
                'taxpayer_name' => $t->taxpayer?->name,
                'base_amount' => $t->base_amount,
                'njop_amount' => $t->njop_amount,
                'rate' => $t->rate,
                'npoptkp_applied' => $t->npoptkp_applied,
                'computed_amount' => $t->computed_amount,
                'billing_code' => $t->billing_code,
                'ntpn' => $t->ntpn,
                'status' => $t->status,
            ]),
            'bpnSubmissions' => $deed->bpnSubmissions()->orderByDesc('id')->get()->map(fn (BpnSubmission $b) => [
                'id' => $b->id,
                'submission_type' => $b->submission_type,
                'submitted_at' => $b->submitted_at?->toDateString(),
                'tracking_number' => $b->tracking_number,
                'pnbp_amount' => $b->pnbp_amount,
                'status' => $b->status,
                'completed_at' => $b->completed_at?->toDateString(),
                'rejection_reason' => $b->rejection_reason,
                'resubmission_of_id' => $b->resubmission_of_id,
            ]),
        ]);
    }

    public function update(UpdatePpatDeedRequest $request, Deed $deed)
    {
        $this->service->update($deed, $request->validated());

        return redirect()->route('legal.ppatDeeds.edit', $deed)
            ->with('success', 'PPAT deed updated.');
    }

    public function destroy(Deed $deed)
    {
        $this->service->delete($deed);

        return redirect()->route('legal.ppatDeeds.index')
            ->with('success', 'PPAT deed deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Deed::class, fn (Deed $deed) => $this->service->delete($deed));
    }
}
