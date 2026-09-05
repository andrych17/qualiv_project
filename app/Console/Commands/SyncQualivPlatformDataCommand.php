<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApBillLine;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArInvoiceLine;
use App\Modules\Accounting\Models\Company;
use App\Modules\CRM\Models\Partner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncQualivPlatformDataCommand extends Command
{
    protected $signature = 'qualiv:sync-platform-data
                            {--tenant=qualiv : Tenant ID to sync data into}
                            {--date= : Specific date to sync in YYYY-MM-DD format}
                            {--days=60 : Number of past days to scan for AI costs and payments}
                            {--usd-rate=16200 : USD to IDR exchange rate for AI costs}
                            {--dry-run : Simulate sync without saving to database}';

    protected $description = 'Synchronize incoming subscription revenue and AI API costs from Qualiv platform and Langfuse into ERP accounting';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?: 'qualiv');
        $isDryRun = (bool) $this->option('dry-run');
        $usdRate = (float) ($this->option('usd-rate') ?: 16200);
        $specificDate = $this->option('date');
        $days = (int) ($this->option('days') ?: 60);

        $this->info("=== Starting Qualiv Platform Data Sync [Tenant: {$tenantId}]" . ($isDryRun ? " (DRY-RUN)" : "") . " ===");

        $tenant = Tenant::query()->find($tenantId);
        if (!$tenant) {
            $this->error("Tenant '{$tenantId}' not found in central database.");
            return 1;
        }

        return $tenant->run(function () use ($isDryRun, $usdRate, $specificDate, $days) {
            $company = Company::query()->first();
            if (!$company) {
                $this->error('No active company found in tenant accounting.');
                return 1;
            }

            // Ensure revenue and expense accounts exist
            $revAccount = Account::query()->firstOrCreate(
                ['company_id' => $company->id, 'account_code' => '41100'],
                [
                    'account_name' => 'Pendapatan Langganan & Topup Platform',
                    'account_type' => 'revenue',
                    'normal_balance' => 'credit',
                    'is_active' => true,
                ]
            );

            $expAccount = Account::query()->firstOrCreate(
                ['company_id' => $company->id, 'account_code' => '61100'],
                [
                    'account_name' => 'Beban API & Infrastruktur Token AI',
                    'account_type' => 'expense',
                    'normal_balance' => 'debit',
                    'is_active' => true,
                ]
            );

            // Ensure AI Provider vendor partner exists
            $aiVendor = Partner::query()->firstOrCreate(
                ['name' => 'AI API Providers (OpenAI / Anthropic / Google)'],
                [
                    'type' => Partner::TYPE_ORGANIZATION,
                    'trade_name' => 'AI Infrastructure & LLM API Services',
                    'source' => 'manual',
                    'is_active' => true,
                ]
            );

            $this->syncPayments($company, $revAccount, $isDryRun, $specificDate, $days);
            $this->syncAiCosts($company, $expAccount, $aiVendor, $usdRate, $isDryRun, $specificDate, $days);

            $this->info('=== Qualiv Platform Data Sync Completed Successfully ===');
            return 0;
        });
    }

    protected function syncPayments(Company $company, Account $revAccount, bool $isDryRun, ?string $specificDate, int $days): void
    {
        $this->line('<comment>1. Checking and synchronizing subscription revenue / payments...</comment>');

        try {
            $query = DB::connection('qualiv_platform')
                ->table('payments as p')
                ->leftJoin('tenants as t', 'p.tenantId', '=', 't.id')
                ->select([
                    'p.id',
                    'p.tenantId',
                    'p.amount',
                    'p.status',
                    'p.type',
                    'p.paidAt',
                    'p.createdAt',
                    'p.description',
                    'p.invoiceId',
                    't.name as tenant_name',
                    't.email as tenant_email',
                ])
                ->where('p.status', 'SUCCESS')
                ->where('p.amount', '>', 0);

            if ($specificDate) {
                $query->whereDate('p.paidAt', $specificDate);
            } else {
                $startDate = Carbon::now()->subDays($days)->startOfDay();
                $query->where('p.paidAt', '>=', $startDate);
            }

            $payments = $query->orderBy('p.paidAt', 'asc')->get();
        } catch (\Throwable $e) {
            $this->warn('Could not connect to qualiv_platform database: ' . $e->getMessage());
            return;
        }

        $this->info("Found {$payments->count()} successful payment record(s) from qualiv_db.");

        $syncedCount = 0;
        foreach ($payments as $payment) {
            $paymentIdShort = substr(str_replace('-', '', $payment->id), 0, 8);
            $invoiceNo = 'INV-QLV-' . strtoupper($paymentIdShort);

            // Check if already synced
            $exists = ArInvoice::query()
                ->where('invoice_no', $invoiceNo)
                ->orWhere(function ($q) use ($payment) {
                    $q->where('subject_type', 'qualiv_platform_payment')
                      ->where('subject_id', (string) $payment->id);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $customerName = trim($payment->tenant_name ?: ($payment->tenant_email ?: 'Qualiv Platform User'));
            $customer = Partner::query()->firstOrCreate(
                ['name' => $customerName],
                [
                    'type' => Partner::TYPE_ORGANIZATION,
                    'trade_name' => $customerName,
                    'source' => 'qualiv_platform',
                    'is_active' => true,
                ]
            );

            $paidDate = Carbon::parse($payment->paidAt ?: $payment->createdAt)->toDateString();
            $amount = (float) $payment->amount;

            $this->line("  -> Creating AR Invoice {$invoiceNo} for {$customerName} (Rp " . number_format($amount, 0, ',', '.') . ") on {$paidDate}");

            if (!$isDryRun) {
                $invoice = ArInvoice::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'partner_id' => $customer->id,
                    'invoice_no' => $invoiceNo,
                    'invoice_type' => ArInvoice::TYPE_STANDARD,
                    'currency_code' => 'IDR',
                    'issue_date' => $paidDate,
                    'due_date' => $paidDate,
                    'subject_type' => 'qualiv_platform_payment',
                    'subject_id' => (string) $payment->id,
                    'status' => ArInvoice::STATUS_PAID,
                    'subtotal' => $amount,
                    'tax_amount' => 0,
                    'total_amount' => $amount,
                    'paid_amount' => $amount,
                    'credited_amount' => 0,
                ]);

                ArInvoiceLine::query()->create([
                    'ar_invoice_id' => $invoice->id,
                    'line_no' => 1,
                    'description' => $payment->description ?: 'Qualiv Platform Subscription / Top-Up (' . $payment->type . ')',
                    'qty' => 1,
                    'unit_price' => $amount,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_amount' => $amount,
                    'revenue_account_id' => $revAccount->id,
                ]);
            }

            $syncedCount++;
        }

        $this->info("Synced {$syncedCount} new payment(s) to Accounting AR Invoices.");
    }

    protected function syncAiCosts(Company $company, Account $expAccount, Partner $aiVendor, float $usdRate, bool $isDryRun, ?string $specificDate, int $days): void
    {
        $this->line('<comment>2. Checking and aggregating AI token usage & cost (Langfuse + Qualiv AI Logs)...</comment>');

        $dailyStats = [];

        // 1. Langfuse observations (USD cost & token counts)
        try {
            $langfuseQuery = DB::connection('langfuse')
                ->table('observations')
                ->selectRaw("
                    DATE(start_time) as usage_date,
                    model,
                    COUNT(*) as trace_count,
                    COALESCE(SUM(calculated_total_cost), 0) as total_usd,
                    COALESCE(SUM(total_tokens), 0) as total_tokens
                ")
                ->whereNotNull('start_time');

            if ($specificDate) {
                $langfuseQuery->whereDate('start_time', $specificDate);
            } else {
                $startDate = Carbon::now()->subDays($days)->startOfDay();
                $langfuseQuery->where('start_time', '>=', $startDate);
            }

            $langfuseRows = $langfuseQuery->groupByRaw('DATE(start_time), model')->get();

            foreach ($langfuseRows as $row) {
                $dateKey = (string) $row->usage_date;
                if (!isset($dailyStats[$dateKey])) {
                    $dailyStats[$dateKey] = [
                        'usd_cost' => 0.0,
                        'tokens' => 0,
                        'traces' => 0,
                        'models' => [],
                    ];
                }
                $dailyStats[$dateKey]['usd_cost'] += (float) $row->total_usd;
                $dailyStats[$dateKey]['tokens'] += (int) $row->total_tokens;
                $dailyStats[$dateKey]['traces'] += (int) $row->trace_count;
                if ($row->model && !in_array($row->model, $dailyStats[$dateKey]['models'], true)) {
                    $dailyStats[$dateKey]['models'][] = $row->model;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Could not read from langfuse database: ' . $e->getMessage());
        }

        // 2. Qualiv platform ai_usage_logs (fallback/supplement token metrics)
        try {
            $aiLogQuery = DB::connection('qualiv_platform')
                ->table('ai_usage_logs')
                ->selectRaw("
                    DATE(\"createdAt\") as usage_date,
                    provider,
                    model,
                    COUNT(*) as call_count,
                    COALESCE(SUM(\"totalTokens\"), 0) as total_tokens
                ");

            if ($specificDate) {
                $aiLogQuery->whereDate('createdAt', $specificDate);
            } else {
                $startDate = Carbon::now()->subDays($days)->startOfDay();
                $aiLogQuery->where('createdAt', '>=', $startDate);
            }

            $aiLogRows = $aiLogQuery->groupByRaw('DATE("createdAt"), provider, model')->get();

            foreach ($aiLogRows as $row) {
                $dateKey = (string) $row->usage_date;
                if (!isset($dailyStats[$dateKey])) {
                    $dailyStats[$dateKey] = [
                        'usd_cost' => 0.0,
                        'tokens' => 0,
                        'traces' => 0,
                        'models' => [],
                    ];
                }
                $dailyStats[$dateKey]['tokens'] += (int) $row->total_tokens;
                $dailyStats[$dateKey]['traces'] += (int) $row->call_count;
                $modelLabel = $row->provider . '/' . $row->model;
                if (!in_array($modelLabel, $dailyStats[$dateKey]['models'], true)) {
                    $dailyStats[$dateKey]['models'][] = $modelLabel;
                }
                // If USD cost was 0 in Langfuse, estimate reasonable baseline (~$0.15 per 1M tokens)
                if ($dailyStats[$dateKey]['usd_cost'] <= 0 && $dailyStats[$dateKey]['tokens'] > 0) {
                    $dailyStats[$dateKey]['usd_cost'] = ($dailyStats[$dateKey]['tokens'] / 1_000_000) * 0.15;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Could not read from qualiv_platform ai_usage_logs: ' . $e->getMessage());
        }

        ksort($dailyStats);
        $this->info("Found " . count($dailyStats) . " date(s) with AI usage metrics.");

        $syncedBillCount = 0;
        foreach ($dailyStats as $date => $stat) {
            $billNo = 'AI-COST-' . str_replace('-', '', $date);

            // Check if already synced
            $exists = ApBill::query()
                ->where('bill_no', $billNo)
                ->orWhere(function ($q) use ($date) {
                    $q->where('subject_type', 'qualiv_ai_cost')
                      ->where('subject_id', (string) $date);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $usd = $stat['usd_cost'];
            $idrAmount = round($usd * $usdRate, 2);
            // Minimum billing floor for accounting record if tokens were consumed
            if ($idrAmount < 500 && $stat['tokens'] > 0) {
                $idrAmount = 500;
            }

            $modelsSummary = implode(', ', array_slice($stat['models'], 0, 3));
            $tokenFormatted = number_format($stat['tokens'], 0, ',', '.');
            $usdFormatted = number_format($usd, 4);

            $this->line("  -> Creating AP Bill {$billNo} for AI Usage on {$date} (Rp " . number_format($idrAmount, 0, ',', '.') . " | {$tokenFormatted} tokens, \${$usdFormatted})");

            if (!$isDryRun) {
                $bill = ApBill::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'partner_id' => $aiVendor->id,
                    'bill_no' => $billNo,
                    'currency_code' => 'IDR',
                    'issue_date' => $date,
                    'due_date' => $date,
                    'subject_type' => 'qualiv_ai_cost',
                    'subject_id' => (string) $date,
                    'status' => ApBill::STATUS_POSTED,
                    'subtotal' => $idrAmount,
                    'tax_amount' => 0,
                    'withheld_amount' => 0,
                    'total_amount' => $idrAmount,
                    'paid_amount' => $idrAmount,
                    'debited_amount' => 0,
                ]);

                ApBillLine::query()->create([
                    'ap_bill_id' => $bill->id,
                    'line_no' => 1,
                    'description' => "Biaya API AI ({$tokenFormatted} tokens, \${$usdFormatted}) - [{$modelsSummary}]",
                    'qty' => 1,
                    'unit_price' => $idrAmount,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_amount' => $idrAmount,
                    'expense_account_id' => $expAccount->id,
                ]);
            }

            $syncedBillCount++;
        }

        $this->info("Synced {$syncedBillCount} new AI cost bill(s) to Accounting AP Bills.");
    }
}
