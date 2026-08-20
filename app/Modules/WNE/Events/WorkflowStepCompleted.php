<?php

namespace App\Modules\WNE\Events;

use App\Modules\WNE\Models\WrkflowInstanceStep;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowStepCompleted
{
    use Dispatchable;

    public function __construct(public WrkflowInstanceStep $instanceStep) {}
}
