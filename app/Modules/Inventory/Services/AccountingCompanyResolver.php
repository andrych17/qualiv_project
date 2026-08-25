<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Models\Company;
use App\Services\TenantFeatureService;

/**
 * Best-effort company resolution for the Accounting GL-posting seam (§5 "Cross-module
 * reuse toward Accounting"). Inventory has no company concept of its own —
 * INVENTORY_SPECS.md never mentions one — and ACCOUNTING_SPECS.md §3K's own answer
 * ("tenant-level default company configuration, overridable per transaction") doesn't
 * exist in code yet (no config surface, no default_company_id anywhere). Multi-company is
 * a real, MVP-priority scenario for this product (§5 — a law firm's operating company vs.
 * its client-trust entity), so guessing wrong here means silently posting GL entries into
 * the wrong legal entity, which is worse than not posting at all.
 *
 * Rather than build that missing config surface as a side effect of §3D/§3E, this resolves
 * only the unambiguous case (Accounting enabled, exactly one active company) and returns
 * null otherwise — the caller skips the GL dispatch entirely; Inventory's own ledger is
 * complete and correct either way, matching "if Accounting isn't installed, nothing else
 * changes" (§5) extended to "or if it can't tell which company."
 */
class AccountingCompanyResolver
{
    public function __construct(protected TenantFeatureService $features) {}

    public function resolve(): ?int
    {
        if (! $this->features->enabled('ACCOUNTING')) {
            return null;
        }

        $activeCompanyIds = Company::query()->where('is_active', true)->pluck('id');

        return $activeCompanyIds->count() === 1 ? $activeCompanyIds->first() : null;
    }
}
