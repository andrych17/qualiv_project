<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use App\Modules\Central\Requests\RejectPaymentRequest;
use App\Modules\Central\Requests\StorePaymentRequest;
use App\Modules\Central\Services\CentralPaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(
        protected CentralPaymentService $service,
    ) {}

    /** Admin-recorded submission from the invoice Show screen. */
    public function store(StorePaymentRequest $request, CentralInvoice $invoice)
    {
        $this->service->submit($invoice, $request->validated());

        return redirect()->route('central.invoices.show', $invoice)->with('success', 'Payment submitted for review.');
    }

    public function confirm(CentralPayment $payment)
    {
        $this->service->confirm($payment, (string) Auth::guard('central_admin')->id());

        return redirect()->route('central.invoices.show', $payment->invoice_id)->with('success', 'Payment confirmed, invoice marked paid.');
    }

    public function reject(RejectPaymentRequest $request, CentralPayment $payment)
    {
        $this->service->reject($payment, (string) Auth::guard('central_admin')->id(), $request->validated('reason'));

        return redirect()->route('central.invoices.show', $payment->invoice_id)->with('success', 'Payment rejected.');
    }

    /** Streams a short-lived link to the uploaded receipt — never proxies the file through PHP. */
    public function receipt(CentralPayment $payment)
    {
        abort_if(! $payment->receipt_object_key, 404);

        $disk = Storage::disk('s3');

        $url = method_exists($disk, 'temporaryUrl')
            ? $disk->temporaryUrl($payment->receipt_object_key, now()->addMinutes(5))
            : $disk->url($payment->receipt_object_key);

        return redirect()->away($url);
    }
}
