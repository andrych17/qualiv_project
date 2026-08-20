<?php

namespace App\Modules\WNE\Events;

use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowInstanceFailed
{
    use Dispatchable;

    public function __construct(public WrkflowInstance $instance, public ?WrkflowInstanceStep $failedStep = null) {}
}
