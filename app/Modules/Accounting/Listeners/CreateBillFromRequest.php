<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\ApBillRequested;
use App\Modules\Accounting\Services\ApBillService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §3E/§5 consuming ApBillRequested. Creates a DRAFT only — same reasoning as
 * CreateInvoiceFromRequest (AR): no automated GL posting without a human review step.
 */
class CreateBillFromRequest implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly ApBillService $bills) {}

    public function handle(ApBillRequested $event): void
    {
        $this->bills->create([
            'company_id' => $event->companyId,
            'partner_id' => $event->partnerId,
            'bill_no' => $event->billNo,
            'currency_code' => $event->currencyCode,
            'issue_date' => $event->issueDate,
            'due_date' => $event->dueDate,
            'vendor_faktur_no' => $event->vendorFakturNo,
            'withholding_type_id' => $event->withholdingTypeId,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], $event->lines, userId: null);
    }
}
