<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3D/§5: the standard shape any module (Sales's Billing Engine, or a vertical
 * module directly) uses to ask Accounting to raise a customer invoice, mirroring
 * WNE's NotificationRequested. Consumed by
 * App\Modules\Accounting\Listeners\CreateInvoiceFromRequest.
 *
 * No real caller exists yet — Sales (§5) isn't built. This event and its listener
 * are the seam §3D is written against from day one, same "engine ships before its
 * caller" precedent as §3M's FakturPajakService.
 *
 * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines
 */
class InvoiceRequested
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $partnerId,
        public string $currencyCode,
        public string $issueDate,
        public string $dueDate,
        public array $lines,
        public string $invoiceType = 'standard',
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}
}
