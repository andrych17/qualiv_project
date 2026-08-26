<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SalesOrderConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public SalesOrder $salesOrder) {}
}
