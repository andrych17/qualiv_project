<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\ApPaymentRequested;
use App\Modules\Accounting\Services\ApPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §3E/§5 consuming ApPaymentRequested. Creates a DRAFT only, same reasoning as
 * RecordPaymentFromRequest (AR).
 */
class RecordApPaymentFromRequest implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly ApPaymentService $payments) {}

    public function handle(ApPaymentRequested $event): void
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
