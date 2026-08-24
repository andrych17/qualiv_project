<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Accounting\Services\InventoryGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** §3H consuming InventoryStockAdjusted — see PostGoodsReceivedToGl's docblock for why this posts immediately instead of drafting. */
class PostStockAdjustmentToGl implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly InventoryGlPostingService $service) {}

    public function handle(InventoryStockAdjusted $event): void
    {
        $this->service->postStockAdjusted($event);
    }
}
