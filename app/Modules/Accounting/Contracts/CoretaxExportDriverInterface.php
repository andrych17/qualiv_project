<?php

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Support\Collection;

/**
 * §3M: v1 targets Coretax's structured-XML bulk-import fallback, not a live API — this
 * interface is what makes a future direct-API driver additive rather than a rewrite,
 * same pluggable-driver shape as WNE's ChannelDriverInterface / Schedule's
 * ConferenceDriverInterface.
 */
interface CoretaxExportDriverInterface
{
    /**
     * @param  Collection<int, TaxFakturPajak|TaxBuktiPotong>  $records
     * @return string XML batch content
     */
    public function export(Company $company, TaxPeriod $period, string $batchType, Collection $records): string;
}
