<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Requests\IssueTaxBillingCodeRequest;
use App\Modules\Legal\Requests\MarkTaxPaidRequest;
use App\Modules\Legal\Requests\UpdateDeedTaxAmountsRequest;
use App\Modules\Legal\Services\TaxService;

class DeedTaxController extends Controller
{
    public function __construct(
        protected TaxService $service,
    ) {}

    public function generate(Deed $deed)
    {
        $this->service->generateForDeed($deed);

        return back()->with('success', 'Tax records generated.');
    }

    public function updateAmounts(UpdateDeedTaxAmountsRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->updateAmounts($tax, $request->validated());

        return back()->with('success', 'Tax amounts updated.');
    }

    public function issueBillingCode(IssueTaxBillingCodeRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->issueBillingCode($tax, $request->validated()['billing_code']);

        return back()->with('success', 'Billing code issued.');
    }

    public function markPaid(MarkTaxPaidRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->markPaid($tax, $request->validated()['ntpn']);

        return back()->with('success', 'Marked paid.');
    }

    public function markValidated(Deed $deed, DeedTax $tax)
    {
        $this->service->markValidated($tax);

        return back()->with('success', 'Tax validated.');
    }
}
