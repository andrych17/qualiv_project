<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Requests\StorePaymentRequest;
use App\Modules\Central\Services\CentralPaymentService;

class PaymentController extends Controller
{
    public function __construct(
        protected CentralPaymentService $service,
    ) {}

    /** Admin action from the invoice Show screen — record payment, mark invoice paid. */
    public function store(StorePaymentRequest $request, CentralInvoice $invoice)
    {
        $this->service->recordAndMarkPaid($invoice, $request->validated());

        return redirect()->route('central.invoices.show', $invoice)->with('success', 'Payment recorded, invoice marked paid.');
    }
}
