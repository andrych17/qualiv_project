<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3E/§5: standard shape for a module to ask Accounting to disburse a vendor payment,
 * mirroring ApBillRequested/PaymentRequested (AR). Consumed by
 * App\Modules\Accounting\Listeners\RecordApPaymentFromRequest. No real caller yet.
 *
 * @param  list<array{ap_bill_id:int, applied_amount:float}>|null  $applications  null = auto-apply oldest-due-first
 */
class ApPaymentRequested
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $partnerId,
        public int $cashGlAccountId,
        public string $currencyCode,
        public string $paymentDate,
        public float $amount,
        public ?array $applications = null,
        public ?string $memo = null,
    ) {}
}
