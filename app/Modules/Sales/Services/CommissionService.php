<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Sales\Models\CommissionPlan;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionService
{
    /**
     * Resolve active commission plan for a user (rep or team).
     */
    public function resolvePlan(int $userId): ?CommissionPlan
    {
        $today = now()->toDateString();

        // 1. Check rep-specific plan
        $plan = CommissionPlan::where('is_active', true)
            ->where('applies_to_type', CommissionPlan::APPLIES_TO_REP)
            ->where('applies_to_user_id', $userId)
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->first();

        if ($plan) {
            return $plan;
        }

        // 2. Fall back to generic active plan
        return CommissionPlan::where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->first();
    }

    /**
     * Calculate commission amount based on plan basis.
     */
    public function calculate(CommissionPlan $plan, float $paidRevenue): float
    {
        if ($plan->basis === CommissionPlan::BASIS_FLAT_PCT) {
            $rate = (float) ($plan->flat_rate_pct ?? 0);

            return round(($paidRevenue * $rate) / 100, 2);
        }

        if ($plan->basis === CommissionPlan::BASIS_TIERED && is_array($plan->tier_rules)) {
            foreach ($plan->tier_rules as $tier) {
                $min = (float) ($tier['min_revenue'] ?? 0);
                $max = isset($tier['max_revenue']) ? (float) $tier['max_revenue'] : INF;
                if ($paidRevenue >= $min && $paidRevenue <= $max) {
                    $rate = (float) ($tier['rate_pct'] ?? 0);

                    return round(($paidRevenue * $rate) / 100, 2);
                }
            }
        }

        return 0.0;
    }

    /**
     * Process commission earned when Accounting records a payment (§3M).
     */
    public function processPaymentCommission(int $paymentId, array $invoiceIds): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $invoices = ArInvoice::whereIn('id', $invoiceIds)
            ->where('subject_type', 'sales.so_hdrs')
            ->get();

        foreach ($invoices as $invoice) {
            if (! $invoice->subject_id) {
                continue;
            }

            $order = SalesOrder::with('lines')->find($invoice->subject_id);
            if (! $order || ! $order->created_by) {
                continue;
            }

            $repId = $order->created_by;
            $plan = $this->resolvePlan($repId);
            if (! $plan) {
                continue;
            }

            $paidRevenue = (float) $invoice->total_amount;
            $commissionAmount = $this->calculate($plan, $paidRevenue);

            if ($commissionAmount <= 0) {
                continue;
            }

            // Find or create draft settlement batch for current month
            $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
            $endOfMonth = Carbon::now()->endOfMonth()->toDateString();

            $settlement = CommissionSettlement::firstOrCreate(
                [
                    'rep_id' => $repId,
                    'period_start' => $startOfMonth,
                    'period_end' => $endOfMonth,
                    'status' => CommissionSettlement::STATUS_DRAFT,
                ],
                [
                    'currency' => 'IDR',
                    'total_amount' => 0,
                ],
            );

            $soLine = $order->lines->first();

            $settlement->lines()->create([
                'commission_plan_id' => $plan->id,
                'so_line_id' => $soLine?->id,
                'line_type' => CommissionSettlementLine::TYPE_EARNED,
                'amount' => $commissionAmount,
                'notes' => 'Earned on invoice #'.$invoice->invoice_no.' (SO #'.$order->so_number.')',
            ]);

            $settlement->total_amount = (float) $settlement->lines()->sum('amount');
            $settlement->save();
        }
    }

    /**
     * Create/generate a new settlement batch.
     */
    public function createSettlement(int $repId, string $periodStart, string $periodEnd): CommissionSettlement
    {
        return CommissionSettlement::create([
            'rep_id' => $repId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => CommissionSettlement::STATUS_DRAFT,
            'total_amount' => 0,
            'currency' => 'IDR',
        ]);
    }

    /**
     * Approve settlement batch.
     */
    public function approve(CommissionSettlement $settlement, int $approverId): CommissionSettlement
    {
        if ($settlement->status !== CommissionSettlement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft settlements can be approved.'],
            ]);
        }

        $settlement->update([
            'status' => CommissionSettlement::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);

        return $settlement;
    }

    /**
     * Mark settlement as paid.
     */
    public function markPaid(CommissionSettlement $settlement): CommissionSettlement
    {
        if ($settlement->status !== CommissionSettlement::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved settlements can be marked as paid.'],
            ]);
        }

        $settlement->update([
            'status' => CommissionSettlement::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return $settlement;
    }
}
