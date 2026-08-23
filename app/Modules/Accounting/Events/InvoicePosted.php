<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3D: fired after ArInvoiceService::post() so the requesting module (e.g. a
 * future Sales's Billing Engine) can update its own local status (`so_lines.
 * qty_invoiced`) without polling Accounting's tables. Carries back exactly the
 * subject_type/subject_id it was given — Accounting never needs to know what a
 * "Sales Order line" is beyond that pointer.
 */
class InvoicePosted
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public ?string $subjectType,
        public ?int $subjectId,
    ) {}
}
