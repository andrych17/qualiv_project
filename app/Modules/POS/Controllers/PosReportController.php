<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3U — POS Reports & Operational Dashboard Controller.
 */
class PosReportController extends Controller
{
    public function index(Request $request): Response
    {
        $today = now()->startOfDay();

        $todayTxns = PosTxnHdr::query()
            ->where('occurred_at', '>=', $today)
            ->get();

        $completedTxns = $todayTxns->where('status', PosTxnHdr::STATUS_COMPLETED);

        $todaySales = (float) $completedTxns->sum('grand_total');
        $txnCount = $completedTxns->count();
        $avgTicket = $txnCount > 0 ? round($todaySales / $txnCount, 2) : 0;

        // Payment mix
        $payments = PosPayment::query()
            ->whereIn('txn_id', $completedTxns->pluck('id'))
            ->select('method', DB::raw('SUM(amount - change_given) as total'))
            ->groupBy('method')
            ->get();

        // Top products today
        $topProducts = PosTxnLine::query()
            ->whereIn('txn_id', $completedTxns->pluck('id'))
            ->select('description', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(line_total) as total_amount'))
            ->groupBy('description')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        // Restaurant metrics
        $occupiedTables = PosTable::query()->where('status', PosTable::STATUS_OCCUPIED)->count();
        $totalTables = PosTable::query()->count();

        return Inertia::render('POS/Reports/Index', [
            'metrics' => [
                'today_sales' => $todaySales,
                'transaction_count' => $txnCount,
                'avg_ticket' => $avgTicket,
                'occupied_tables' => $occupiedTables,
                'total_tables' => $totalTables,
            ],
            'payment_mix' => $payments,
            'top_products' => $topProducts,
        ]);
    }
}
