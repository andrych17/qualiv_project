<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Contract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractRenewed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Contract $contract) {}
}
