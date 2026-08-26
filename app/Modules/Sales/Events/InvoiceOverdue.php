<?php

namespace App\Modules\Sales\Events;

use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceOverdue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Partner $customer,
        public array $invoiceData,
    ) {}
}
