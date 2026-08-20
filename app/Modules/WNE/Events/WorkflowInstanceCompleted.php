<?php

namespace App\Modules\WNE\Events;

use App\Modules\WNE\Models\WrkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowInstanceCompleted
{
    use Dispatchable;

    public function __construct(public WrkflowInstance $instance) {}
}
