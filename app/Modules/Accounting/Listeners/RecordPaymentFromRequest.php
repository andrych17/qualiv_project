<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\PaymentRequested;
use App\Modules\Accounting\Services\ArPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §3D/§5 consuming PaymentRequested. Creates a DRAFT only, same reasoning as
 * CreateInvoiceFromRequest — no automated GL posting without a human review step.
 */
class RecordPaymentFromRequest implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly ArPaymentService $payments) {}

    public function handle(PaymentRequested $event): void
    {
        $this->payments->create([
            'company_id' => $event->companyId,
            'partner_id' => $event->partnerId,
            'cash_gl_account_id' => $event->cashGlAccountId,
            'currency_code' => $event->currencyCode,
            'payment_date' => $event->paymentDate,
            'amount' => $event->amount,
            'memo' => $event->memo,
        ], $event->applications, userId: null);
    }
}
