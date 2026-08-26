<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Accounting\Events\PaymentRecorded;
use App\Modules\Sales\Services\CommissionService;

class ProcessCommissionOnPaymentRecorded
{
    public function __construct(protected CommissionService $commissionService) {}

    public function handle(PaymentRecorded $event): void
    {
        $this->commissionService->processPaymentCommission($event->paymentId, $event->invoiceIds);
    }
}
