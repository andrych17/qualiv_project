<?php

namespace App\Modules\Sales\Events;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditBlocked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Partner $customer,
        public SalesOrder $salesOrder,
        public float $currentExposure,
        public float $creditLimit,
    ) {}
}
