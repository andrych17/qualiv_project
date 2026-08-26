<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
