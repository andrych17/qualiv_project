<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $metrics = [
            'open_prs_count' => PurRequisitionHdr::query()
                ->whereIn('status', [PurRequisitionHdr::STATUS_DRAFT, PurRequisitionHdr::STATUS_PENDING_APPROVAL, PurRequisitionHdr::STATUS_APPROVED])
                ->count(),
            'open_pos_count' => PurOrderHdr::query()
                ->whereIn('status', [
                    PurOrderHdr::STATUS_DRAFT,
                    PurOrderHdr::STATUS_PENDING_APPROVAL,
                    PurOrderHdr::STATUS_APPROVED,
                    PurOrderHdr::STATUS_SENT,
                    PurOrderHdr::STATUS_ACKNOWLEDGED,
                    PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
                ])
                ->count(),
            'pending_receipts_count' => PurOrderHdr::query()
                ->whereIn('status', [
                    PurOrderHdr::STATUS_SENT,
                    PurOrderHdr::STATUS_ACKNOWLEDGED,
                    PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
                ])
                ->count(),
            'pending_invoices_count' => PurInvoiceHdr::query()
                ->whereIn('status', [PurInvoiceHdr::STATUS_CAPTURED, PurInvoiceHdr::STATUS_PENDING_APPROVAL])
                ->count(),
            'open_exceptions_count' => PurException::query()
                ->where('status', PurException::STATUS_OPEN)
                ->count(),
        ];

        $exceptions = PurException::query()
            ->where('status', PurException::STATUS_OPEN)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (PurException $e) => [
                'id' => $e->id,
                'exception_type' => $e->exception_type,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'summary' => $e->summary,
                'status' => $e->status,
                'created_at' => $e->created_at?->toDateTimeString(),
            ]);

        $recentPrs = PurRequisitionHdr::query()
            ->with(['requester:id,name', 'department:id,name'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (PurRequisitionHdr $pr) => [
                'id' => $pr->id,
                'pr_no' => $pr->pr_no,
                'requester_name' => $pr->requester?->name,
                'estimated_total' => (float) $pr->estimated_total,
                'status' => $pr->status,
                'created_at' => $pr->created_at?->toDateString(),
            ]);

        $recentPos = PurOrderHdr::query()
            ->with(['supplier:id,name'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (PurOrderHdr $po) => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'supplier_name' => $po->supplier?->name,
                'total_amount' => (float) $po->total_amount,
                'currency_code' => $po->currency_code,
                'status' => $po->status,
                'created_at' => $po->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchase/Dashboard', [
            'metrics' => $metrics,
            'exceptions' => $exceptions,
            'recentPrs' => $recentPrs,
            'recentPos' => $recentPos,
        ]);
    }
}
