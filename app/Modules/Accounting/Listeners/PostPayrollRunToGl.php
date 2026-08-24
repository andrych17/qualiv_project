<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Services\PayrollGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** §3S consuming PayrollRunPaid — posts immediately, same "the run is already finalized, nothing left to review" reasoning as §3H's InventoryGlPostingService listeners (see PostGoodsReceivedToGl's docblock). */
class PostPayrollRunToGl implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly PayrollGlPostingService $service) {}

    public function handle(PayrollRunPaid $event): void
    {
        $this->service->postRunPaid($event);
    }
}
