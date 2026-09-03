<?php

namespace App\Modules\PP\Listeners;

use App\Modules\PP\Services\DemandAggregationService;
use App\Modules\Sales\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

/** PP_SPECS.md §3B — a confirmed Sales order becomes real demand, read-only against SALES.so_hdrs. */
class SyncDemandFromSalesOrder implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly DemandAggregationService $demand) {}

    public function handle(SalesOrderConfirmed $event): void
    {
        $this->demand->syncFromSalesOrder($event->salesOrder);
    }
}
