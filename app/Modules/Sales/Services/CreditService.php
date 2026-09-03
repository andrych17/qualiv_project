<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Services\AccountingService;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Events\CreditBlocked;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreditService
{
    public function __construct(
        protected AccountingService $accountingService,
    ) {}

    /**
     * Get credit profile and current open AR exposure for a customer.
     */
    public function getExposure(int $partnerId): array
    {
        $profile = CustomerCreditProfile::where('partner_id', $partnerId)->first();
        $creditLimit = $profile ? (float) $profile->credit_limit : 0.0;
        $onHold = $profile ? (bool) $profile->on_hold : false;
        $paymentTerms = $profile ? (int) $profile->payment_terms_days : 30;

        $openArBalance = 0.0;

        // Check open AR balance from Accounting if table exists (§3K: via AccountingService)
        if (Schema::hasTable('ACCOUNTING.ar_invoices')) {
            $openArBalance = $this->accountingService->getOpenARBalance($partnerId);
        }

        $availableCredit = max(0.0, $creditLimit - $openArBalance);

        return [
            'credit_limit' => $creditLimit,
            'open_ar_balance' => $openArBalance,
            'available_credit' => $availableCredit,
            'on_hold' => $onHold,
            'payment_terms_days' => $paymentTerms,
        ];
    }

    /**
     * Synchronous credit check on Sales Order confirmation (§3K/§3F).
     *
     * @throws ValidationException
     */
    public function check(SalesOrder $order, bool $ignoreOverride = false): void
    {
        $partnerId = $order->customer_id;
        $customer = Partner::find($partnerId);
        $customerName = $customer ? $customer->name : 'Customer';

        $exposure = $this->getExposure($partnerId);

        if ($exposure['on_hold']) {
            throw ValidationException::withMessages([
                'credit' => ["Account for {$customerName} is currently on hold. New orders cannot be confirmed without administrator clearance."],
            ]);
        }

        // If credit limit is 0, no limit restriction is imposed unless on_hold is set
        if ($exposure['credit_limit'] <= 0) {
            return;
        }

        $orderTotal = (float) $order->total_amount;
        $totalExposure = $exposure['open_ar_balance'] + $orderTotal;

        if ($totalExposure > $exposure['credit_limit']) {
            $exceeded = $totalExposure - $exposure['credit_limit'];
            $formattedExceeded = number_format($exceeded, 2);

            if ($customer) {
                event(new CreditBlocked($customer, $order, $totalExposure, $exposure['credit_limit']));
            }

            throw ValidationException::withMessages([
                'credit' => ["This order exceeds {$customerName}'s credit limit by {$formattedExceeded}. Request an override or reduce the order."],
            ]);
        }
    }
}
