<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3D/§5: standard shape for a module to ask Accounting to record a customer
 * payment, mirroring InvoiceRequested. Consumed by
 * App\Modules\Accounting\Listeners\RecordPaymentFromRequest. No real caller yet
 * — see InvoiceRequested's docblock.
 *
 * @param  list<array{ar_invoice_id:int, applied_amount:float}>|null  $applications  null = auto-apply oldest-first
 */
class PaymentRequested
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
