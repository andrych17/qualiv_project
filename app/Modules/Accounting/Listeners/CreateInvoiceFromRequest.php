<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\InvoiceRequested;
use App\Modules\Accounting\Services\ArInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §3D/§5 consuming InvoiceRequested. Creates a DRAFT only — deliberately does
 * NOT call ArInvoiceService::post(). Whether an automated caller (Sales's
 * Billing Engine, once it exists) wants its invoices auto-posted is a policy
 * decision that belongs to that caller, not inferred here; posting to the GL
 * with no human in the loop is exactly what §3P's "never auto-posts" discipline
 * exists to prevent. A person reviews and posts from the AR Invoices screen.
 */
class CreateInvoiceFromRequest implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly ArInvoiceService $invoices) {}

    public function handle(InvoiceRequested $event): void
    {
        $this->invoices->create([
            'company_id' => $event->companyId,
            'partner_id' => $event->partnerId,
            'currency_code' => $event->currencyCode,
            'issue_date' => $event->issueDate,
            'due_date' => $event->dueDate,
            'invoice_type' => $event->invoiceType,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], $event->lines, userId: null);
    }
}
