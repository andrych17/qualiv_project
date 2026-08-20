<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CustomFields\Services\CustomFieldService;
use Illuminate\Support\Facades\DB;

class PartnerService
{
    public const ENTITY = 'crm_partner';

    public function __construct(
        protected CustomFieldService $customFields,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, string $type, string $source = 'manual'): Partner
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($data, $type, $source, $custom) {
            $partner = Partner::query()->create([
                ...$this->partnerAttributes($data),
                'type' => $type,
                'source' => $source,
            ]);

            $this->syncAddresses($partner, $data['addresses'] ?? []);
            $this->syncContactPoints($partner, $data['contact_points'] ?? []);
            $this->syncRoles($partner, $data['role_type_ids'] ?? []);
            $this->customFields->sync(self::ENTITY, $partner->id, $custom);

            return $partner;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Partner $partner, array $data): Partner
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($partner, $data, $custom) {
            $partner->update($this->partnerAttributes($data));

            $this->syncAddresses($partner, $data['addresses'] ?? []);
            $this->syncContactPoints($partner, $data['contact_points'] ?? []);
            $this->syncRoles($partner, $data['role_type_ids'] ?? []);
            $this->customFields->sync(self::ENTITY, $partner->id, $custom);

            return $partner->refresh();
        });
    }

    /**
     * §3B rule: deactivating a Contact/Company never cascades into vertical
     * modules — it only marks the partner inactive, never a row delete, so any
     * existing FK from Sales/Legal/Property still resolves.
     */
    public function deactivate(Partner $partner): void
    {
        $partner->update(['is_active' => false]);
    }

    /** @param  array<string, mixed>  $data */
    private function partnerAttributes(array $data): array
    {
        return [
            // empty() (not ??/?:) — safe on a missing key (Contact payloads never send
            // industry_id; Company payloads never send title_position) and also turns a
            // blank-select '' into null so it never hits the nullable FK columns as a string.
            'parent_partner_id' => empty($data['parent_partner_id']) ? null : $data['parent_partner_id'],
            'name' => $data['name'],
            'trade_name' => $data['trade_name'] ?? null,
            'title_position' => $data['title_position'] ?? null,
            'registration_tax_id' => $data['registration_tax_id'] ?? null,
            'industry_id' => empty($data['industry_id']) ? null : $data['industry_id'],
            'tags' => $this->parseTags($data['tags'] ?? null),
            'owner_id' => empty($data['owner_id']) ? null : $data['owner_id'],
        ];
    }

    private function parseTags(mixed $tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter($tags, fn ($t) => trim((string) $t) !== ''));
        }

        if (! is_string($tags) || trim($tags) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncAddresses(Partner $partner, array $rows): void
    {
        Address::query()->where('partner_id', $partner->id)->delete();

        foreach ($rows as $row) {
            if (empty($row['line1'])) {
                continue;
            }

            Address::query()->create([
                'partner_id' => $partner->id,
                'type' => $row['type'] ?? 'office',
                'line1' => $row['line1'],
                'line2' => $row['line2'] ?? null,
                'city' => $row['city'] ?? null,
                'state_province' => $row['state_province'] ?? null,
                'postal_code' => $row['postal_code'] ?? null,
                'country' => $row['country'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncContactPoints(Partner $partner, array $rows): void
    {
        ContactPoint::query()->where('partner_id', $partner->id)->delete();

        foreach ($rows as $row) {
            if (empty($row['value'])) {
                continue;
            }

            ContactPoint::query()->create([
                'partner_id' => $partner->id,
                'type' => $row['type'] ?? 'email',
                'value' => $row['value'],
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'opt_out' => (bool) ($row['opt_out'] ?? false),
            ]);
        }
    }

    /**
     * Roles keep history (§4 "with history") — an assignment being dropped from
     * the form deactivates the existing row rather than deleting it; a role
     * re-added later is a fresh active row, not a resurrection of the old one.
     *
     * @param  list<int>  $roleTypeIds
     */
    private function syncRoles(Partner $partner, array $roleTypeIds): void
    {
        $roleTypeIds = array_map('intval', $roleTypeIds);

        PartnerRole::query()
            ->where('partner_id', $partner->id)
            ->where('is_active', true)
            ->whereNotIn('role_type_id', $roleTypeIds)
            ->update(['is_active' => false]);

        $active = PartnerRole::query()
            ->where('partner_id', $partner->id)
            ->where('is_active', true)
            ->pluck('role_type_id')
            ->all();

        foreach (array_diff($roleTypeIds, $active) as $roleTypeId) {
            PartnerRole::query()->create([
                'partner_id' => $partner->id,
                'role_type_id' => $roleTypeId,
                'assigned_at' => now(),
                'assigned_by' => auth()->id(),
                'is_active' => true,
            ]);
        }
    }
}
