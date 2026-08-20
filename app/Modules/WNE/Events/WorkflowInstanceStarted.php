<?php

namespace App\Modules\WNE\Events;

use App\Modules\WNE\Models\WrkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;

/** §3C — a calling module (e.g. HCM) can subscribe without WNE knowing anything about it. */
class WorkflowInstanceStarted
{
    use Dispatchable;

    public function __construct(public WrkflowInstance $instance) {}
}
