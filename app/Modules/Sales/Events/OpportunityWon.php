<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Opportunity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityWon
{
    use Dispatchable, SerializesModels;

    public function __construct(public Opportunity $opportunity) {}
}
