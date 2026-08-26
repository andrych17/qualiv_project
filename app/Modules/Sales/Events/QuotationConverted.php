<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationConverted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public SalesOrder $salesOrder,
    ) {}
}
