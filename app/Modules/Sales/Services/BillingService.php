<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Sales\Events\InvoiceOverdue;
use App\Modules\Sales\Models\ContractSubscription;
use App\Modules\Sales\Models\RecurringBillingSchedule;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BillingService
{
    /**
     * Request invoice generation for a Sales Order via Accounting (§3I).
     */
    public function generateInvoiceForOrder(SalesOrder $order, array $options = [], ?int $userId = null): ?ArInvoice
    {
        if (! in_array($order->status, [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED], true)) {
            throw ValidationException::withMessages([
                'order' => ['Invoices can only be requested for confirmed or fulfilled orders.'],
            ]);
        }

        if (! class_exists(ArInvoiceService::class) || ! Schema::hasTable('ACCOUNTING.ar_invoices')) {
            throw ValidationException::withMessages([
                'accounting' => ['Accounting module is required to generate customer invoices.'],
            ]);
        }

        return DB::transaction(function () use ($order, $options, $userId) {
            $order->load(['lines', 'customer']);

            // Find primary company in Accounting
            $company = Company::query()->first();
            if (! $company) {
                throw ValidationException::withMessages([
                    'company' => ['No active company found in Accounting module.'],
                ]);
            }

            // Find default revenue account
            $revenueAccount = Account::query()
                ->where('company_id', $company->id)
                ->where('account_type', Account::TYPE_REVENUE)
                ->first();

            if (! $revenueAccount) {
                $revenueAccount = Account::query()->where('company_id', $company->id)->first();
            }

            $lines = [];
            foreach ($order->lines as $soLine) {
                $unbilledQty = (float) $soLine->qty_ordered - (float) $soLine->qty_invoiced;
                if ($unbilledQty <= 0) {
                    continue;
                }

                $lines[] = [
                    'description' => $soLine->description,
                    'qty' => $unbilledQty,
                    'unit_price' => (float) $soLine->unit_price,
                    'discount_amount' => (float) $soLine->discount_amount,
                    'tax_code_id' => null,
                    'revenue_account_id' => $revenueAccount ? $revenueAccount->id : 1,
                ];
            }

            if (empty($lines)) {
                throw ValidationException::withMessages([
                    'lines' => ['All lines in this order have already been fully invoiced.'],
                ]);
            }

            $issueDate = $options['issue_date'] ?? now()->toDateString();
            $dueDate = $options['due_date'] ?? now()->addDays(30)->toDateString();

            $arInvoiceService = app(ArInvoiceService::class);
            $invoice = $arInvoiceService->create([
                'company_id' => $company->id,
                'partner_id' => $order->customer_id,
                'currency_code' => 'IDR',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'invoice_type' => $options['invoice_type'] ?? ArInvoice::TYPE_STANDARD,
                'subject_type' => 'sales.so_hdrs',
                'subject_id' => $order->id,
            ], $lines, $userId);

            // Update order lines qty_invoiced
            foreach ($order->lines as $soLine) {
                $unbilledQty = (float) $soLine->qty_ordered - (float) $soLine->qty_invoiced;
                if ($unbilledQty > 0) {
                    $soLine->increment('qty_invoiced', $unbilledQty);
                }
            }

            return $invoice;
        });
    }

    /**
     * Process due recurring billing schedules (§3I / §3L).
     */
    public function processRecurringSchedules(?string $asOfDate = null): int
    {
        $today = $asOfDate ? Carbon::parse($asOfDate)->toDateString() : now()->toDateString();

        $dueSchedules = RecurringBillingSchedule::with(['subscription.contract', 'customer'])
            ->where('is_active', true)
            ->where('next_bill_date', '<=', $today)
            ->get();

        $processedCount = 0;

        foreach ($dueSchedules as $schedule) {
            $subscription = $schedule->subscription;
            if (! $subscription || ! $subscription->is_active || ! $subscription->contract || $subscription->contract->status !== 'active') {
                continue;
            }

            // Create AR invoice in Accounting if available
            if (class_exists(ArInvoiceService::class) && Schema::hasTable('ACCOUNTING.ar_invoices')) {
                $company = Company::query()->first();
                if ($company) {
                    $revenueAccount = Account::query()
                        ->where('company_id', $company->id)
                        ->where('account_type', Account::TYPE_REVENUE)
                        ->first();

                    $arInvoiceService = app(ArInvoiceService::class);
                    $arInvoiceService->create([
                        'company_id' => $company->id,
                        'partner_id' => $schedule->customer_id,
                        'currency_code' => $subscription->currency ?? 'IDR',
                        'issue_date' => $today,
                        'due_date' => Carbon::parse($today)->addDays(30)->toDateString(),
                        'invoice_type' => ArInvoice::TYPE_STANDARD,
                        'subject_type' => 'sales.contr_subscriptions',
                        'subject_id' => $subscription->id,
                    ], [
                        [
                            'description' => $subscription->description.' (Recurring)',
                            'qty' => 1,
                            'unit_price' => (float) $subscription->recurring_amount,
                            'discount_amount' => 0,
                            'tax_code_id' => null,
                            'revenue_account_id' => $revenueAccount ? $revenueAccount->id : 1,
                        ],
                    ], null);
                }
            }

            // Advance next_bill_date
            $nextDate = Carbon::parse($schedule->next_bill_date);
            if ($subscription->billing_interval === ContractSubscription::INTERVAL_MONTHLY) {
                $nextDate->addMonth();
            } elseif ($subscription->billing_interval === ContractSubscription::INTERVAL_QUARTERLY) {
                $nextDate->addMonths(3);
            } elseif ($subscription->billing_interval === ContractSubscription::INTERVAL_ANNUAL) {
                $nextDate->addYear();
            }

            $schedule->update([
                'next_bill_date' => $nextDate->toDateString(),
                'last_billed_at' => now(),
            ]);

            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Check for overdue customer invoices and fire notifications (§3I dunning).
     */
    public function checkOverdueInvoices(): int
    {
        if (! Schema::hasTable('ACCOUNTING.ar_invoices')) {
            return 0;
        }

        $overdueInvoices = ArInvoice::with('partner')
            ->whereIn('status', [ArInvoice::STATUS_POSTED, ArInvoice::STATUS_PARTIALLY_PAID])
            ->where('due_date', '<', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($overdueInvoices as $invoice) {
            if ($invoice->partner) {
                event(new InvoiceOverdue($invoice->partner, [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'balance_due' => $invoice->openBalance(),
                ]));
                $count++;
            }
        }

        return $count;
    }
}
