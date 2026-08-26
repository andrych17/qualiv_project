<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SalesDashboardService
{
    public function getDashboardData(?int $userId = null): array
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfMonth = $now->copy()->endOfMonth()->toDateString();

        // 1. Open Quotes
        $openQuotesQuery = Quotation::with('lines')->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT, Quotation::STATUS_APPROVED]);
        $openQuotesCount = (clone $openQuotesQuery)->count();
        $openQuotes = (clone $openQuotesQuery)->get();
        $openQuotesValue = (float) $openQuotes->sum(fn ($q) => $q->total_amount);

        // 2. Open Orders
        $openOrdersQuery = SalesOrder::with('lines')->whereIn('status', [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED]);
        $openOrdersCount = (clone $openOrdersQuery)->count();
        $openOrders = (clone $openOrdersQuery)->get();
        $openOrdersValue = (float) $openOrders->sum(fn ($o) => $o->total_amount);

        // 3. Revenue MTD
        $mtdOrders = SalesOrder::with('lines')
            ->whereIn('status', [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED])
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth.' 23:59:59'])
            ->get();
        $revenueMtd = (float) $mtdOrders->sum(fn ($o) => $o->total_amount);

        // 4. Overdue Invoices & Customers over limit
        $overdueCount = 0;
        $overdueValue = 0.0;
        $overdueInvoices = [];

        if (Schema::hasTable('ACCOUNTING.ar_invoices')) {
            $overdueList = ArInvoice::with('partner')
                ->whereIn('status', [ArInvoice::STATUS_POSTED, ArInvoice::STATUS_PARTIALLY_PAID])
                ->where('due_date', '<', $now->toDateString())
                ->orderBy('due_date')
                ->get();

            $overdueCount = $overdueList->count();
            $overdueValue = (float) $overdueList->sum(fn ($inv) => $inv->openBalance());
            $overdueInvoices = $overdueList->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_no' => $inv->invoice_no,
                'customer_name' => $inv->partner?->name ?? 'Unknown',
                'due_date' => $inv->due_date?->format('Y-m-d'),
                'amount' => $inv->openBalance(),
            ])->all();
        }

        // 5. Open Returns
        $openReturnsCount = SalesReturn::whereIn('status', [SalesReturn::STATUS_REQUESTED, SalesReturn::STATUS_APPROVED, SalesReturn::STATUS_RECEIVED])->count();

        // 6. Over Limit Customers
        $overLimitCount = CustomerCreditProfile::where('on_hold', true)->count();

        // 7. Funnel Stats
        $oppCount = Opportunity::count();
        $oppValue = (float) Opportunity::sum('estimated_value');

        $quotedCount = Quotation::count();
        $quotedValue = (float) Quotation::with('lines')->get()->sum(fn ($q) => $q->total_amount);

        $orderedCount = SalesOrder::whereIn('status', [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED])->count();
        $orderedValue = (float) SalesOrder::whereIn('status', [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED])->with('lines')->get()->sum(fn ($o) => $o->total_amount);

        $funnel = [
            'opportunities' => ['count' => $oppCount, 'value' => $oppValue],
            'quotations' => ['count' => $quotedCount, 'value' => $quotedValue],
            'orders' => ['count' => $orderedCount, 'value' => $orderedValue],
        ];

        // 8. My Work Queues
        $myOpportunities = Opportunity::with('customer')
            ->when($userId, fn ($q) => $q->where('owner_id', $userId))
            ->whereNotIn('stage', [Opportunity::STAGE_WON, Opportunity::STAGE_LOST])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $myQuotes = Quotation::with(['customer', 'lines'])
            ->when($userId, fn ($q) => $q->where('created_by', $userId))
            ->whereNotIn('status', [Quotation::STATUS_CONVERTED, Quotation::STATUS_DECLINED, Quotation::STATUS_EXPIRED])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $myOrders = SalesOrder::with(['customer', 'lines'])
            ->when($userId, fn ($q) => $q->where('created_by', $userId))
            ->whereNotIn('status', [SalesOrder::STATUS_FULFILLED, SalesOrder::STATUS_CANCELLED])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'summary' => [
                'open_quotes_count' => $openQuotesCount,
                'open_quotes_value' => $openQuotesValue,
                'open_orders_count' => $openOrdersCount,
                'open_orders_value' => $openOrdersValue,
                'revenue_mtd' => $revenueMtd,
                'overdue_invoices_count' => $overdueCount,
                'overdue_invoices_value' => $overdueValue,
                'open_returns_count' => $openReturnsCount,
                'customers_over_limit' => $overLimitCount,
            ],
            'funnel' => $funnel,
            'my_opportunities' => $myOpportunities,
            'my_quotes' => $myQuotes,
            'my_orders' => $myOrders,
            'overdue_invoices' => $overdueInvoices,
        ];
    }
}
