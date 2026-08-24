<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Accounting\Services\InventoryGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** §3H consuming InventoryGoodsIssued — see PostGoodsReceivedToGl's docblock for why this posts immediately instead of drafting. */
class PostGoodsIssuedToGl implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly InventoryGlPostingService $service) {}

    public function handle(InventoryGoodsIssued $event): void
    {
        $this->service->postGoodsIssued($event);
    }
}
