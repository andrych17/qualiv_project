<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\Partner;
use App\Modules\CustomFields\Services\CustomFieldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public const ENTITY = 'crm_lead';

    public function __construct(
        protected PartnerService $partners,
        protected CustomFieldService $customFields,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Lead
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);
        unset($data['custom_fields']);

        return DB::transaction(function () use ($data, $custom) {
            $lead = Lead::query()->create([...$data, 'stage' => Lead::STAGE_NEW]);
            $this->customFields->sync(self::ENTITY, $lead->id, $custom);

            return $lead;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Lead $lead, array $data): Lead
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);
        unset($data['custom_fields']);

        return DB::transaction(function () use ($lead, $data, $custom) {
            $lead->update($data);
            $this->customFields->sync(self::ENTITY, $lead->id, $custom);

            return $lead->refresh();
        });
    }

    /**
     * Direct stage set for the Kanban drag path — New/Contacted/Qualified only.
     * Converted/Disqualified always go through convert()/disqualify() instead,
     * since both need data (a Role, a reason) a bare drag can't supply
     * (DESIGN.md: "a drop that would violate a hard business rule opens the
     * relevant inline form instead of silently completing the move").
     */
    public function setStage(Lead $lead, string $stage): Lead
    {
        if (! in_array($stage, Lead::DIRECT_STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'That stage requires Convert or Disqualify instead.']);
        }

        if (in_array($lead->stage, [Lead::STAGE_CONVERTED, Lead::STAGE_DISQUALIFIED], true)) {
            throw ValidationException::withMessages(['stage' => 'A converted or disqualified lead cannot be moved.']);
        }

        $lead->update(['stage' => $stage]);

        return $lead;
    }

    public function logActivity(Lead $lead, string $activityType, ?string $body): LeadActivity
    {
        return LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'activity_type' => $activityType,
            'body' => $body,
            'logged_by' => auth()->id(),
            'logged_at' => now(),
        ]);
    }

    /**
     * §3D: "creates ... a partners record, assigns the chosen initial Role,
     * ... marks the lead Converted with a link to the resulting partner."
     * Always creates a new Partner — the dedupe-or-link half of that sentence
     * is 3G's job (Partner Merge/Dedup), not conversion's, so it's deferred.
     */
    public function convert(Lead $lead, string $partnerType, int $roleTypeId): Partner
    {
        if ($lead->stage === Lead::STAGE_CONVERTED || $lead->stage === Lead::STAGE_DISQUALIFIED) {
            throw ValidationException::withMessages(['stage' => 'This lead has already been closed.']);
        }

        $name = $partnerType === Partner::TYPE_ORGANIZATION
            ? ($lead->company_name ?: $lead->name)
            : $lead->name;

        return DB::transaction(function () use ($lead, $partnerType, $roleTypeId, $name) {
            $partner = $this->partners->create(
                ['name' => $name, 'role_type_ids' => [$roleTypeId]],
                $partnerType,
                'lead_conversion',
            );

            $lead->update([
                'stage' => Lead::STAGE_CONVERTED,
                'converted_partner_id' => $partner->id,
            ]);

            $this->logActivity($lead, 'note', "Converted to partner: {$partner->name}");

            return $partner;
        });
    }

    public function disqualify(Lead $lead, string $reason): Lead
    {
        if ($lead->stage === Lead::STAGE_CONVERTED || $lead->stage === Lead::STAGE_DISQUALIFIED) {
            throw ValidationException::withMessages(['stage' => 'This lead has already been closed.']);
        }

        $lead->update([
            'stage' => Lead::STAGE_DISQUALIFIED,
            'disqualify_reason' => $reason,
        ]);

        $this->logActivity($lead, 'note', "Disqualified: {$reason}");

        return $lead;
    }
}
