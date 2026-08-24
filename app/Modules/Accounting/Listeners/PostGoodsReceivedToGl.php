<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Services\InventoryGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** §3H consuming InventoryGoodsReceived — unlike §3D's draft-only listeners, this posts immediately (see InventoryGlPostingService's docblock for why auto-posting is correct here). */
class PostGoodsReceivedToGl implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly InventoryGlPostingService $service) {}

    public function handle(InventoryGoodsReceived $event): void
    {
        $this->service->postGoodsReceived($event);
    }
}
