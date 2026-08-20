<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerMergeLog;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerMergeService
{
    private const CONFLICT_FIELDS = ['name', 'trade_name', 'title_position', 'registration_tax_id', 'industry_id'];

    /**
     * §3G: "background/report view surfaces likely-duplicate partners by
     * name/email/phone similarity — a review queue, not automatic merging."
     * Two deterministic signals (no fuzzy-match extension assumed installed):
     * exact normalized name match (same type), and a shared contact_point value.
     *
     * @return array<int, array{reason: string, partners: Collection}>
     */
    public function duplicateGroups(): array
    {
        $groups = [];

        $nameRows = DB::table('CRM.partners')
            ->selectRaw('lower(trim(name)) as norm_name, array_agg(id ORDER BY id) as partner_ids')
            ->where('is_active', true)
            ->whereNull('merged_into_partner_id')
            ->groupBy('type', DB::raw('lower(trim(name))'))
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($nameRows as $row) {
            $groups[] = ['reason' => 'Same name', 'partner_ids' => $this->parsePgIntArray($row->partner_ids)];
        }

        $contactRows = DB::table('CRM.contact_points as cp')
            ->join('CRM.partners as p', 'p.id', '=', 'cp.partner_id')
            ->selectRaw('cp.type as contact_type, lower(trim(cp.value)) as norm_value, array_agg(DISTINCT p.id ORDER BY p.id) as partner_ids')
            ->where('p.is_active', true)
            ->whereNull('p.merged_into_partner_id')
            ->groupBy('cp.type', DB::raw('lower(trim(cp.value))'))
            ->havingRaw('count(DISTINCT p.id) > 1')
            ->get();

        foreach ($contactRows as $row) {
            $groups[] = ['reason' => 'Same '.$row->contact_type, 'partner_ids' => $this->parsePgIntArray($row->partner_ids)];
        }

        $allIds = collect($groups)->flatMap(fn ($g) => $g['partner_ids'])->unique();
        $partners = Partner::query()->whereIn('id', $allIds)->get()->keyBy('id');

        return collect($groups)
            ->map(fn ($g) => [
                'reason' => $g['reason'],
                'partners' => collect($g['partner_ids'])->map(fn ($id) => $partners->get($id))->filter()->values(),
            ])
            ->filter(fn ($g) => $g['partners']->count() > 1)
            ->values()
            ->all();
    }

    public function merge(Partner $survivor, Partner $loser, ?int $mergedBy): PartnerMergeLog
    {
        if ($survivor->id === $loser->id) {
            throw ValidationException::withMessages(['loser_partner_id' => 'Survivor and loser must be different partners.']);
        }
        if ($survivor->type !== $loser->type) {
            throw ValidationException::withMessages(['loser_partner_id' => 'Can only merge partners of the same type.']);
        }
        if ($survivor->merged_into_partner_id !== null || $loser->merged_into_partner_id !== null) {
            throw ValidationException::withMessages(['loser_partner_id' => 'One of these partners has already been merged.']);
        }
        if ($loser->id === $survivor->parent_partner_id || $survivor->id === $loser->parent_partner_id) {
            throw ValidationException::withMessages(['loser_partner_id' => 'These two are in a parent/child relationship — merge is not supported for that case.']);
        }

        return DB::transaction(function () use ($survivor, $loser, $mergedBy) {
            $conflicts = $this->fieldConflicts($survivor, $loser);

            // Roles: survivor's active role_types win — deactivate the loser's colliding
            // duplicates first (uq_crm_partner_roles_active is WHERE is_active), then move
            // everything else (remaining actives + all history) onto the survivor.
            $survivorActiveRoleTypeIds = PartnerRole::query()
                ->where('partner_id', $survivor->id)->where('is_active', true)->pluck('role_type_id');
            PartnerRole::query()->where('partner_id', $loser->id)->where('is_active', true)
                ->whereIn('role_type_id', $survivorActiveRoleTypeIds)
                ->update(['is_active' => false]);
            PartnerRole::query()->where('partner_id', $loser->id)->update(['partner_id' => $survivor->id]);

            // Contacts under a merged Company.
            Partner::query()->where('parent_partner_id', $loser->id)->update(['parent_partner_id' => $survivor->id]);

            // Force is_primary=false on the move — uq_crm_*_primary is WHERE is_primary,
            // and the survivor may already have its own primary of the same type.
            Address::query()->where('partner_id', $loser->id)->update(['partner_id' => $survivor->id, 'is_primary' => false]);
            ContactPoint::query()->where('partner_id', $loser->id)->update(['partner_id' => $survivor->id, 'is_primary' => false]);

            Lead::query()->where('converted_partner_id', $loser->id)->update(['converted_partner_id' => $survivor->id]);
            ServiceCase::query()->where('partner_id', $loser->id)->update(['partner_id' => $survivor->id]);
            Ticket::query()->where('partner_id', $loser->id)->update(['partner_id' => $survivor->id]);

            // Flatten any prior tombstone chain pointing at the loser, so any FK that
            // resolves merged_into_partner_id needs only one hop, never a chain.
            Partner::query()->where('merged_into_partner_id', $loser->id)->update(['merged_into_partner_id' => $survivor->id]);

            $loser->update(['is_active' => false, 'merged_into_partner_id' => $survivor->id]);

            return PartnerMergeLog::query()->create([
                'merged_from_partner_id' => $loser->id,
                'merged_into_partner_id' => $survivor->id,
                'merged_by' => $mergedBy,
                'merged_at' => now(),
                'field_conflicts' => $conflicts,
            ]);
        });
    }

    /** @return array<string, array{kept: mixed, discarded: mixed}> */
    private function fieldConflicts(Partner $survivor, Partner $loser): array
    {
        $conflicts = [];
        foreach (self::CONFLICT_FIELDS as $field) {
            if ($survivor->{$field} !== $loser->{$field}) {
                $conflicts[$field] = ['kept' => $survivor->{$field}, 'discarded' => $loser->{$field}];
            }
        }

        return $conflicts;
    }

    /** PDO doesn't auto-cast a Postgres `array_agg` result — parse "{1,2,3}" into [1,2,3]. */
    private function parsePgIntArray(string $raw): array
    {
        $trimmed = trim($raw, '{}');
        if ($trimmed === '') {
            return [];
        }

        return array_map('intval', explode(',', $trimmed));
    }
}
