<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3E/§5: standard shape a module (Purchase's matched vendor invoice, or a vertical
 * module's direct expense) uses to ask Accounting to raise a vendor bill — the AP mirror
 * of InvoiceRequested. "Ap"-prefixed rather than a bare `BillRequested` because Purchase's
 * own intake step (`PURCHASE_SPECS.md` §3F) may one day want a differently-shaped event of
 * its own; this one is specifically "create an AP ledger entry." Consumed by
 * App\Modules\Accounting\Listeners\CreateBillFromRequest. No real caller yet — Purchase
 * isn't built (§5 build order has Accounting before Purchase).
 *
 * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, expense_account_id:int}>  $lines
 */
class ApBillRequested
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $partnerId,
        public string $billNo,
        public string $currencyCode,
        public string $issueDate,
        public string $dueDate,
        public array $lines,
        public ?string $vendorFakturNo = null,
        public ?int $withholdingTypeId = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}
}
