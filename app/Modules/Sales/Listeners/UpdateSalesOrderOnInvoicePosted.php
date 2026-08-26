<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Accounting\Events\InvoicePosted;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;

class UpdateSalesOrderOnInvoicePosted
{
    public function handle(InvoicePosted $event): void
    {
        if ($event->subjectType === 'sales.so_lines' && $event->subjectId) {
            $line = SalesOrderLine::find($event->subjectId);
            if ($line) {
                // If subject is a specific so_line, update qty_invoiced
                $invoice = ArInvoice::with('lines')->find($event->invoiceId);
                if ($invoice) {
                    $billedQty = (float) $invoice->lines->sum('qty');
                    $line->qty_invoiced = min((float) $line->qty_ordered, (float) $line->qty_invoiced + ($billedQty > 0 ? $billedQty : (float) $line->qty_ordered));
                    $line->save();
                }
            }
        } elseif ($event->subjectType === 'sales.so_hdrs' && $event->subjectId) {
            $order = SalesOrder::with('lines')->find($event->subjectId);
            if ($order) {
                foreach ($order->lines as $line) {
                    $line->qty_invoiced = $line->qty_ordered;
                    $line->save();
                }
            }
        }
    }
}
