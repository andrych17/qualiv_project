<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Models\PartyRoleType;
use App\Modules\Legal\Requests\StoreDeedRequest;
use App\Modules\Legal\Requests\UpdateDeedRequest;
use App\Modules\Legal\Services\DeedService;
use App\Modules\SysConfig\Services\ConfigService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeedController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['deed_number', 'category', 'status', 'signing_date', 'created_at'];

    public function __construct(
        protected DeedService $service,
        protected CustomFieldService $customFields,
        protected ConfigService $config,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $graceDays = (int) ($this->config->get('LEGAL', 'DPW_GRACE_DAYS', 'LEGAL') ?? 14);

        $deeds = Deed::query()
            ->notary()
            ->with(['deedType:id,name,category', 'matter:id,code,title', 'will'])
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
                'uuid' => $d->uuid,
                'deed_number' => $d->deed_number,
                'deed_type_name' => $d->deedType?->name,
                'matter_code' => $d->matter?->code,
                'category' => $d->category,
                'status' => $d->status,
                'signing_date_formatted' => $d->signing_date?->format('d M Y'),
                'created_at_formatted' => $d->created_at?->format('d M Y'),
                // §3D dashboard flag — surfaced inline here since 3A's dashboard isn't built yet.
                'will_dpw_overdue' => $d->will?->isOverdueForDpw($graceDays) ?? false,
            ]);

        return Inertia::render('Legal/Deeds/Index', [
            'deeds' => $deeds,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/Deeds/Create', [
            'deedTypes' => DeedType::query()->where('category', DeedType::CATEGORY_NOTARY)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::query()->orderByDesc('id')->limit(200)->get(['id', 'code', 'title']),
            'customFields' => $this->customFields->formPayload(DeedService::ENTITY),
        ]);
    }

    public function store(StoreDeedRequest $request)
    {
        $deed = $this->service->create($request->validated());

        return redirect()->route('legal.deeds.edit', $deed)
            ->with('success', 'Deed drafted.');
    }

    public function edit(Deed $deed): Response
    {
        $deed->loadMissing('deedType', 'will');

        return Inertia::render('Legal/Deeds/Edit', [
            'deed' => [
                'id' => $deed->id,
                'matter_id' => $deed->matter_id,
                'deed_type_id' => $deed->deed_type_id,
                'deed_type_code' => $deed->deedType->code,
                'category' => $deed->category,
                'deed_number' => $deed->deed_number,
                'status' => $deed->status,
                'signing_date' => $deed->signing_date?->toDateString(),
                'minuta_reference' => $deed->minuta_reference,
                'summary' => $deed->summary,
                'is_locked' => $deed->isLocked(),
                'next_statuses' => Deed::TRANSITIONS[$deed->status] ?? [],
            ],
            'will' => $deed->will ? [
                'id' => $deed->will->id,
                'testator_partner_id' => $deed->will->testator_partner_id,
                'dpw_reg_number' => $deed->will->dpw_reg_number,
                'dpw_registered_at' => $deed->will->dpw_registered_at?->toDateString(),
                'status' => $deed->will->status,
                'closing_notes' => $deed->will->closing_notes,
            ] : null,
            'deedTypes' => DeedType::query()->where('category', DeedType::CATEGORY_NOTARY)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::query()->orderByDesc('id')->limit(200)->get(['id', 'code', 'title']),
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
        ]);
    }

    public function update(UpdateDeedRequest $request, Deed $deed)
    {
        $this->service->update($deed, $request->validated());

        return redirect()->route('legal.deeds.edit', $deed)
            ->with('success', 'Deed updated.');
    }

    public function transition(Request $request, Deed $deed)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Deed::STATUSES)],
        ]);

        $this->service->transition($deed, $validated['status']);

        return redirect()->route('legal.deeds.edit', $deed)
            ->with('success', 'Deed status updated.');
    }

    public function destroy(Deed $deed)
    {
        $this->service->delete($deed);

        return redirect()->route('legal.deeds.index')
            ->with('success', 'Deed deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Deed::class, fn (Deed $deed) => $this->service->delete($deed));
    }
}
