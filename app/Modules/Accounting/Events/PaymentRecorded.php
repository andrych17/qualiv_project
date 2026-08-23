<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3D: fired after ArPaymentService::post() so a requesting module (e.g. Sales's
 * Commission Engine) can react without polling Accounting's tables directly.
 *
 * @param  list<int>  $invoiceIds  invoices this payment was applied against
 */
class PaymentRecorded
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public array $invoiceIds,
    ) {}
}
