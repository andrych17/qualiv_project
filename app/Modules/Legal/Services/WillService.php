<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\Will;
use RuntimeException;

class WillService
{
    public function create(Deed $deed, int $testatorPartnerId): Will
    {
        if (Will::query()->where('deed_id', $deed->id)->exists()) {
            throw new RuntimeException('A will record already exists for this deed.');
        }

        return Will::query()->create([
            'deed_id' => $deed->id,
            'testator_partner_id' => $testatorPartnerId,
            'status' => Will::STATUS_DRAFTED,
        ]);
    }

    /** §3D — the statutory Daftar Pusat Wasiat obligation. */
    public function registerDpw(Will $will, string $regNumber, ?string $registeredAt = null): Will
    {
        if ($will->status !== Will::STATUS_DRAFTED) {
            throw new RuntimeException('Only a drafted will can be registered with DPW.');
        }

        $will->update([
            'dpw_reg_number' => $regNumber,
            'dpw_registered_at' => $registeredAt ?? now()->toDateString(),
            'status' => Will::STATUS_DPW_REGISTERED,
        ]);

        return $will->refresh();
    }

    public function activate(Will $will): Will
    {
        if ($will->status !== Will::STATUS_DPW_REGISTERED) {
            throw new RuntimeException('Only a DPW-registered will can be activated.');
        }

        $will->update(['status' => Will::STATUS_ACTIVE]);

        return $will->refresh();
    }

    /** Execution/probate — logged, never a silent status flip (§3D). */
    public function open(Will $will, string $notes): Will
    {
        if ($will->status !== Will::STATUS_ACTIVE) {
            throw new RuntimeException('Only an active will can be opened.');
        }

        $will->update(['status' => Will::STATUS_OPENED, 'closing_notes' => $notes]);

        return $will->refresh();
    }

    public function revoke(Will $will, string $reason): Will
    {
        if (in_array($will->status, [Will::STATUS_OPENED, Will::STATUS_REVOKED], true)) {
            throw new RuntimeException('An opened or already-revoked will cannot be revoked again.');
        }

        $will->update(['status' => Will::STATUS_REVOKED, 'closing_notes' => $reason]);

        return $will->refresh();
    }
}
