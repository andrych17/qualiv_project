<?php

namespace App\Modules\Legal\Services;

use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedParty;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §3J — party/appearer management. identity_snapshot is captured once at add-time and
 * never re-read from CRM.partners afterward (§5 "why snapshot, not live reference").
 * Locked the same way as the deed itself once signed (§3C immutability).
 */
class DeedPartyService
{
    /** @param  array<string, mixed>  $data */
    public function add(Deed $deed, array $data): DeedParty
    {
        $this->assertUnlocked($deed);

        $snapshot = $this->buildSnapshot($data);
        $partnerId = $data['partner_id'] ?? null;

        if (! $partnerId) {
            $partnerId = Partner::query()->create([
                'type' => Partner::TYPE_INDIVIDUAL,
                'name' => $snapshot['name'] ?? 'Unnamed party',
                'source' => 'legal_quick_add',
            ])->id;
        }

        return DB::transaction(fn () => DeedParty::query()->create([
            'deed_id' => $deed->id,
            'partner_id' => $partnerId,
            'role_type_id' => $data['role_type_id'],
            'identity_snapshot' => $snapshot,
        ]));
    }

    /** @param  array<string, mixed>  $data */
    public function update(DeedParty $party, array $data): DeedParty
    {
        $this->assertUnlocked($party->deed);

        $party->update([
            'role_type_id' => $data['role_type_id'] ?? $party->role_type_id,
            'identity_snapshot' => $this->buildSnapshot($data),
        ]);

        return $party->refresh();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed> */
    private function buildSnapshot(array $data): array
    {
        return array_filter([
            'name' => $data['identity_name'] ?? null,
            'id_number' => $data['identity_id_number'] ?? null,
            'address' => $data['identity_address'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function remove(DeedParty $party): void
    {
        $this->assertUnlocked($party->deed);

        $party->delete();
    }

    private function assertUnlocked(Deed $deed): void
    {
        if ($deed->isLocked()) {
            throw new RuntimeException('Signed deeds are immutable — parties cannot be added, edited, or removed.');
        }
    }
}
