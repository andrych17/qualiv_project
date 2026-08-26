<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeliveryShipped
{
    use Dispatchable, SerializesModels;

    public function __construct(public Delivery $delivery) {}
}
