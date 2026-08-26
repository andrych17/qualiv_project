<?php

namespace App\Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §3I / §5 Generic billable-request contract.
 * Any Core or Vertical module can populate and fire this event to request a Sales Order.
 */
class SalesOrderRequested
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{
     *     subject_type: string,
     *     subject_id: int,
     *     customer_id: int,
     *     price_list_id?: ?int,
     *     lines: list<array{
     *         item_type?: string,
     *         product_id?: ?int,
     *         description: string,
     *         qty_ordered: float,
     *         unit_price: float,
     *         discount_amount?: float,
     *         tax_amount?: float
     *     }>,
     *     created_by?: ?int
     * }  $payload
     */
    public function __construct(public array $payload) {}
}
