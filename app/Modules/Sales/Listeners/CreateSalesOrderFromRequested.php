<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Events\SalesOrderRequested;
use App\Modules\Sales\Services\SalesOrderService;

class CreateSalesOrderFromRequested
{
    public function __construct(protected SalesOrderService $salesOrderService) {}

    public function handle(SalesOrderRequested $event): void
    {
        $this->salesOrderService->createFromExternalRequest($event->payload);
    }
}
