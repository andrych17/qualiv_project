<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3E: fired after ApPaymentService::post() (disbursement). "Ap"-prefixed to avoid
 * colliding with the AR-side PaymentRecorded event.
 *
 * @param  list<int>  $billIds  bills this payment was applied against
 */
class ApPaymentRecorded
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public array $billIds,
    ) {}
}
