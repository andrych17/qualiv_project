<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\CRM\Requests\ConvertLeadRequest;
use App\Modules\CRM\Requests\DisqualifyLeadRequest;
use App\Modules\CRM\Requests\StoreLeadRequest;
use App\Modules\CRM\Requests\UpdateLeadRequest;
use App\Modules\CRM\Requests\UpdateLeadStageRequest;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CustomFields\Services\CustomFieldService;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(
        protected LeadService $service,
        protected CustomFieldService $customFields,
    ) {}

    /**
     * All leads unpaginated — the Board view needs the full pipeline at once,
     * and a firm's lead volume is small enough that the List view can just
     * filter/sort the same flat array client-side (same pattern as Projects'
     * issue board — see resources/js/Pages/Projects/Projects/Show.vue).
     */
    public function index(): Response
    {
        $leads = Lead::query()
            ->with(['source:id,name', 'owner:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'company_name' => $l->company_name,
                'stage' => $l->stage,
                'source_name' => $l->source?->name,
                'owner_id' => $l->owner_id,
                'owner_name' => $l->owner?->name,
                'estimated_value' => $l->estimated_value,
                'estimated_value_formatted' => $l->estimated_value !== null ? number_format((float) $l->estimated_value, 0) : null,
                'next_action_at' => $l->next_action_at?->toDateString(),
                'next_action_formatted' => $l->next_action_at?->format('d M Y'),
                'is_overdue' => $l->next_action_at !== null
                    && $l->next_action_at->isPast()
                    && ! in_array($l->stage, [Lead::STAGE_CONVERTED, Lead::STAGE_DISQUALIFIED], true),
                'disqualify_reason' => $l->disqualify_reason,
            ]);

        return Inertia::render('CRM/Leads/Index', [
            'leads' => $leads,
            'roleTypes' => PartnerRoleType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CRM/Leads/Create', [
            ...$this->formProps(),
            'customFields' => $this->customFields->formPayload(LeadService::ENTITY),
        ]);
    }

    public function store(StoreLeadRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('crm.leads.index')->with('success', 'Lead added.');
    }

    public function edit(Lead $lead): Response
    {
        return Inertia::render('CRM/Leads/Edit', [
            ...$this->formProps(),
            'lead' => [
                'id' => $lead->id,
                'name' => $lead->name,
                'company_name' => $lead->company_name,
                'source_id' => $lead->source_id,
                'stage' => $lead->stage,
                'owner_id' => $lead->owner_id,
                'estimated_value' => $lead->estimated_value,
                'next_action_at' => $lead->next_action_at?->format('Y-m-d\TH:i'),
                'notes' => $lead->notes,
                'disqualify_reason' => $lead->disqualify_reason,
                'converted_partner' => $lead->convertedPartner ? [
                    'id' => $lead->convertedPartner->id,
                    'name' => $lead->convertedPartner->name,
                    'type' => $lead->convertedPartner->type,
                ] : null,
                'activities' => $lead->activities()->with('loggedBy:id,name')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'activity_type' => $a->activity_type,
                    'body' => $a->body,
                    'logged_by_name' => $a->loggedBy?->name,
                    'logged_at_formatted' => $a->logged_at?->format('d M Y H:i'),
                ]),
            ],
            'customFields' => $this->customFields->formPayload(LeadService::ENTITY, $lead->id),
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        $this->service->update($lead, $request->validated());

        return redirect()->route('crm.leads.index')->with('success', 'Lead updated.');
    }

    public function updateStage(UpdateLeadStageRequest $request, Lead $lead)
    {
        $this->service->setStage($lead, $request->validated()['stage']);

        return back()->with('success', 'Lead stage updated.');
    }

    public function convert(ConvertLeadRequest $request, Lead $lead)
    {
        $data = $request->validated();
        $partner = $this->service->convert($lead, $data['partner_type'], (int) $data['role_type_id']);

        $route = $partner->type === Partner::TYPE_ORGANIZATION ? 'crm.companies.edit' : 'crm.contacts.edit';

        return redirect()->route($route, $partner->id)->with('success', 'Lead converted to partner.');
    }

    public function disqualify(DisqualifyLeadRequest $request, Lead $lead)
    {
        $this->service->disqualify($lead, $request->validated()['reason']);

        return back()->with('success', 'Lead disqualified.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
            'roleTypes' => PartnerRoleType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
