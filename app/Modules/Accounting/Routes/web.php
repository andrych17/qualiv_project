<?php

use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\AccountLedgerController;
use App\Modules\Accounting\Controllers\AllocationRuleController;
use App\Modules\Accounting\Controllers\AllocationRunController;
use App\Modules\Accounting\Controllers\ApAgingController;
use App\Modules\Accounting\Controllers\ApBillController;
use App\Modules\Accounting\Controllers\ApDebitNoteController;
use App\Modules\Accounting\Controllers\ApPaymentController;
use App\Modules\Accounting\Controllers\ArAgingController;
use App\Modules\Accounting\Controllers\ArCreditNoteController;
use App\Modules\Accounting\Controllers\ArInvoiceController;
use App\Modules\Accounting\Controllers\ArPaymentController;
use App\Modules\Accounting\Controllers\AssetDisposalController;
use App\Modules\Accounting\Controllers\AssetGroupController;
use App\Modules\Accounting\Controllers\AuditLogController;
use App\Modules\Accounting\Controllers\BalanceSheetController;
use App\Modules\Accounting\Controllers\BankAccountController;
use App\Modules\Accounting\Controllers\BankReconciliationController;
use App\Modules\Accounting\Controllers\BankStatementImportController;
use App\Modules\Accounting\Controllers\BudgetController;
use App\Modules\Accounting\Controllers\BudgetVsActualController;
use App\Modules\Accounting\Controllers\CashFlowController;
use App\Modules\Accounting\Controllers\CashTransactionController;
use App\Modules\Accounting\Controllers\CashTransferController;
use App\Modules\Accounting\Controllers\CompanyController;
use App\Modules\Accounting\Controllers\ControlReconciliationController;
use App\Modules\Accounting\Controllers\CoretaxExportController;
use App\Modules\Accounting\Controllers\CostCenterController;
use App\Modules\Accounting\Controllers\DepreciationRunController;
use App\Modules\Accounting\Controllers\ExchangeRateController;
use App\Modules\Accounting\Controllers\FakturPajakBlockController;
use App\Modules\Accounting\Controllers\FiscalYearController;
use App\Modules\Accounting\Controllers\FixedAssetController;
use App\Modules\Accounting\Controllers\InventoryGlMappingController;
use App\Modules\Accounting\Controllers\InventoryPostingFailureController;
use App\Modules\Accounting\Controllers\JournalController;
use App\Modules\Accounting\Controllers\PayrollComponentGlMappingController;
use App\Modules\Accounting\Controllers\PayrollPostingFailureController;
use App\Modules\Accounting\Controllers\ProfitLossController;
use App\Modules\Accounting\Controllers\RecurringArTemplateController;
use App\Modules\Accounting\Controllers\RecurringJournalTemplateController;
use App\Modules\Accounting\Controllers\ReportingHubController;
use App\Modules\Accounting\Controllers\TaxCodeController;
use App\Modules\Accounting\Controllers\TaxPeriodController;
use App\Modules\Accounting\Controllers\TrialBalanceController;
use App\Modules\Accounting\Controllers\WithholdingTypeController;
use Illuminate\Support\Facades\Route;

// §3B ships first — Accounts is the section landing page for now (no §3A dashboard
// yet), same "point straight at the built page" convention WNE/DMS used before
// their own dashboards existed. Keep this, SysConfigSeeder's ACCOUNTING menu_link,
// and AppBreadcrumb.vue's SECTION_HOME in sync.
Route::redirect('/accounting', '/accounting/accounts');

Route::middleware(['auth', 'verified', 'module:ACCOUNTING', 'menu.perm:ACCOUNTING'])
    ->prefix('accounting')
    ->name('accounting.')
    ->group(function () {
        // §3K minimal Companies master (§3B's own dependency).
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');

        // §3B Chart of Accounts — 'create' before '{account}' routes, same ordering
        // constraint as every other resource route in this codebase.
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('companies/{company}/seed-starter-coa', [AccountController::class, 'seedStarterCoa'])->name('accounts.seed-starter');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        // §3B fiscal calendar — a fiscal year always ships with its 12 periods; period
        // status (§3O locking) is a row action, not its own CRUD resource.
        Route::get('fiscal-years', [FiscalYearController::class, 'index'])->name('fiscal-years.index');
        Route::get('fiscal-years/create', [FiscalYearController::class, 'create'])->name('fiscal-years.create');
        Route::post('fiscal-years', [FiscalYearController::class, 'store'])->name('fiscal-years.store');
        Route::put('fiscal-periods/{period}/status', [FiscalYearController::class, 'updatePeriodStatus'])->name('fiscal-periods.status');

        // §3B/§3I Cost centers.
        Route::get('cost-centers', [CostCenterController::class, 'index'])->name('cost-centers.index');
        Route::get('cost-centers/create', [CostCenterController::class, 'create'])->name('cost-centers.create');
        Route::post('cost-centers', [CostCenterController::class, 'store'])->name('cost-centers.store');
        Route::get('cost-centers/{costCenter}/edit', [CostCenterController::class, 'edit'])->name('cost-centers.edit');
        Route::put('cost-centers/{costCenter}', [CostCenterController::class, 'update'])->name('cost-centers.update');
        Route::delete('cost-centers/{costCenter}', [CostCenterController::class, 'destroy'])->name('cost-centers.destroy');

        // §3L Multi Currency — exchange rate CRUD; AR/AP posting reads through
        // ExchangeRateService::rateFor(), not directly.
        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::get('exchange-rates/create', [ExchangeRateController::class, 'create'])->name('exchange-rates.create');
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
        Route::get('exchange-rates/{exchangeRate}/edit', [ExchangeRateController::class, 'edit'])->name('exchange-rates.edit');
        Route::put('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update'])->name('exchange-rates.update');
        Route::delete('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy'])->name('exchange-rates.destroy');

        // §3C General Ledger / Journal Entries — the single posting path.
        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/create', [JournalController::class, 'create'])->name('journals.create');
        Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
        Route::get('journals/{journal}/edit', [JournalController::class, 'edit'])->name('journals.edit');
        Route::put('journals/{journal}', [JournalController::class, 'update'])->name('journals.update');
        Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');
        Route::post('journals/{journal}/post', [JournalController::class, 'post'])->name('journals.post');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');

        // §3M Indonesian Tax Engine — built before AR/AP (§3D/§3E) per ACCOUNTING_SPECS.md
        // §5's suggested build order, so these lookups/registers have no live poster yet
        // (see each service's docblock); the config/setup screens are still real and usable.
        Route::get('tax-codes', [TaxCodeController::class, 'index'])->name('tax-codes.index');
        Route::get('tax-codes/create', [TaxCodeController::class, 'create'])->name('tax-codes.create');
        Route::post('tax-codes', [TaxCodeController::class, 'store'])->name('tax-codes.store');
        Route::get('tax-codes/{taxCode}/edit', [TaxCodeController::class, 'edit'])->name('tax-codes.edit');
        Route::put('tax-codes/{taxCode}', [TaxCodeController::class, 'update'])->name('tax-codes.update');
        Route::delete('tax-codes/{taxCode}', [TaxCodeController::class, 'destroy'])->name('tax-codes.destroy');

        Route::get('withholding-types', [WithholdingTypeController::class, 'index'])->name('withholding-types.index');
        Route::get('withholding-types/create', [WithholdingTypeController::class, 'create'])->name('withholding-types.create');
        Route::post('withholding-types', [WithholdingTypeController::class, 'store'])->name('withholding-types.store');
        Route::get('withholding-types/{withholdingType}/edit', [WithholdingTypeController::class, 'edit'])->name('withholding-types.edit');
        Route::put('withholding-types/{withholdingType}', [WithholdingTypeController::class, 'update'])->name('withholding-types.update');
        Route::delete('withholding-types/{withholdingType}', [WithholdingTypeController::class, 'destroy'])->name('withholding-types.destroy');

        Route::get('faktur-blocks', [FakturPajakBlockController::class, 'index'])->name('faktur-blocks.index');
        Route::get('faktur-blocks/create', [FakturPajakBlockController::class, 'create'])->name('faktur-blocks.create');
        Route::post('faktur-blocks', [FakturPajakBlockController::class, 'store'])->name('faktur-blocks.store');
        Route::post('faktur-blocks/{block}/deactivate', [FakturPajakBlockController::class, 'deactivate'])->name('faktur-blocks.deactivate');

        Route::get('tax-periods', [TaxPeriodController::class, 'index'])->name('tax-periods.index');
        Route::get('tax-periods/create', [TaxPeriodController::class, 'create'])->name('tax-periods.create');
        Route::post('tax-periods', [TaxPeriodController::class, 'store'])->name('tax-periods.store');
        Route::post('tax-periods/{period}/mark-filed', [TaxPeriodController::class, 'markFiled'])->name('tax-periods.mark-filed');

        Route::get('coretax-exports', [CoretaxExportController::class, 'index'])->name('coretax-exports.index');
        Route::post('coretax-exports', [CoretaxExportController::class, 'store'])->name('coretax-exports.store');
        Route::get('coretax-exports/{batch}/download', [CoretaxExportController::class, 'download'])->name('coretax-exports.download');

        // §3D Accounts Receivable — built against §3M from the start (post() always
        // resolves tax codes and issues Faktur Pajak, see ArInvoiceService docblock).
        Route::get('ar-invoices', [ArInvoiceController::class, 'index'])->name('ar-invoices.index');
        Route::get('ar-invoices/create', [ArInvoiceController::class, 'create'])->name('ar-invoices.create');
        Route::post('ar-invoices', [ArInvoiceController::class, 'store'])->name('ar-invoices.store');
        Route::get('ar-invoices/{invoice}/edit', [ArInvoiceController::class, 'edit'])->name('ar-invoices.edit');
        Route::put('ar-invoices/{invoice}', [ArInvoiceController::class, 'update'])->name('ar-invoices.update');
        Route::delete('ar-invoices/{invoice}', [ArInvoiceController::class, 'destroy'])->name('ar-invoices.destroy');
        Route::post('ar-invoices/{invoice}/post', [ArInvoiceController::class, 'post'])->name('ar-invoices.post');
        Route::get('ar-invoices/{invoice}', [ArInvoiceController::class, 'show'])->name('ar-invoices.show');

        // Payment application — create()+post() run together (human review = form submit).
        Route::get('ar-payments', [ArPaymentController::class, 'index'])->name('ar-payments.index');
        Route::get('ar-payments/create', [ArPaymentController::class, 'create'])->name('ar-payments.create');
        Route::get('ar-payments/open-invoices', [ArPaymentController::class, 'openInvoicesFor'])->name('ar-payments.open-invoices');
        Route::post('ar-payments', [ArPaymentController::class, 'store'])->name('ar-payments.store');

        // Credit notes — v1 scope is invoice-linked only, issued inline from the invoice Show page (no index/show).
        Route::post('ar-credit-notes', [ArCreditNoteController::class, 'store'])->name('ar-credit-notes.store');

        Route::get('ar-aging', [ArAgingController::class, 'index'])->name('ar-aging.index');

        // §3E Accounts Payable — mirrors §3D structurally; built against §3M the same way.
        Route::get('ap-bills', [ApBillController::class, 'index'])->name('ap-bills.index');
        Route::get('ap-bills/create', [ApBillController::class, 'create'])->name('ap-bills.create');
        Route::post('ap-bills', [ApBillController::class, 'store'])->name('ap-bills.store');
        Route::get('ap-bills/{bill}/edit', [ApBillController::class, 'edit'])->name('ap-bills.edit');
        Route::put('ap-bills/{bill}', [ApBillController::class, 'update'])->name('ap-bills.update');
        Route::delete('ap-bills/{bill}', [ApBillController::class, 'destroy'])->name('ap-bills.destroy');
        Route::post('ap-bills/{bill}/post', [ApBillController::class, 'post'])->name('ap-bills.post');
        Route::get('ap-bills/{bill}', [ApBillController::class, 'show'])->name('ap-bills.show');

        Route::get('ap-payments', [ApPaymentController::class, 'index'])->name('ap-payments.index');
        Route::get('ap-payments/create', [ApPaymentController::class, 'create'])->name('ap-payments.create');
        Route::get('ap-payments/open-bills', [ApPaymentController::class, 'openBillsFor'])->name('ap-payments.open-bills');
        Route::post('ap-payments', [ApPaymentController::class, 'store'])->name('ap-payments.store');

        Route::post('ap-debit-notes', [ApDebitNoteController::class, 'store'])->name('ap-debit-notes.store');

        Route::get('ap-aging', [ApAgingController::class, 'index'])->name('ap-aging.index');

        // §3F Cash & Bank Management — bank_accounts.show() is the GL-derived cash
        // book (see BankAccountController class docblock), not a cash_transactions list.
        Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::get('bank-accounts/create', [BankAccountController::class, 'create'])->name('bank-accounts.create');
        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::get('bank-accounts/{bankAccount}/edit', [BankAccountController::class, 'edit'])->name('bank-accounts.edit');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
        Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])->name('bank-accounts.show');

        Route::get('cash-transactions/create', [CashTransactionController::class, 'create'])->name('cash-transactions.create');
        Route::post('cash-transactions', [CashTransactionController::class, 'store'])->name('cash-transactions.store');

        Route::get('cash-transfers/create', [CashTransferController::class, 'create'])->name('cash-transfers.create');
        Route::post('cash-transfers', [CashTransferController::class, 'store'])->name('cash-transfers.store');

        Route::get('bank-statement-imports', [BankStatementImportController::class, 'index'])->name('bank-statement-imports.index');
        Route::get('bank-statement-imports/create', [BankStatementImportController::class, 'create'])->name('bank-statement-imports.create');
        Route::post('bank-statement-imports', [BankStatementImportController::class, 'store'])->name('bank-statement-imports.store');
        Route::get('bank-statement-imports/{bankStatementImport}', [BankStatementImportController::class, 'show'])->name('bank-statement-imports.show');

        // §3Q Reconcile — bank rec workspace (base-currency accounts only, see
        // BankReconciliationService docblock) + the read-only AR/AP control report.
        Route::get('bank-reconciliation/{bankAccount}', [BankReconciliationController::class, 'show'])->name('bank-reconciliation.show');
        Route::post('bank-reconciliation/{bankAccount}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliation.auto-match');
        Route::post('bank-reconciliation/{bankAccount}/match', [BankReconciliationController::class, 'match'])->name('bank-reconciliation.match');
        Route::post('bank-reconciliation/{bankAccount}/lines/{bankStatementLine}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('bank-reconciliation.unmatch');
        Route::post('bank-reconciliation/{bankAccount}/lines/{bankStatementLine}/ignore', [BankReconciliationController::class, 'ignore'])->name('bank-reconciliation.ignore');
        Route::post('bank-reconciliation/{bankAccount}/lines/{bankStatementLine}/unignore', [BankReconciliationController::class, 'unignore'])->name('bank-reconciliation.unignore');

        Route::get('control-reconciliation', [ControlReconciliationController::class, 'index'])->name('control-reconciliation.index');

        // §3G Fixed Assets — asset register + dual commercial/fiscal depreciation.
        Route::get('asset-groups', [AssetGroupController::class, 'index'])->name('asset-groups.index');
        Route::get('asset-groups/create', [AssetGroupController::class, 'create'])->name('asset-groups.create');
        Route::post('asset-groups', [AssetGroupController::class, 'store'])->name('asset-groups.store');
        Route::post('companies/{company}/seed-starter-asset-groups', [AssetGroupController::class, 'seedStarter'])->name('asset-groups.seed-starter');
        Route::get('asset-groups/{assetGroup}/edit', [AssetGroupController::class, 'edit'])->name('asset-groups.edit');
        Route::put('asset-groups/{assetGroup}', [AssetGroupController::class, 'update'])->name('asset-groups.update');
        Route::delete('asset-groups/{assetGroup}', [AssetGroupController::class, 'destroy'])->name('asset-groups.destroy');

        Route::get('fixed-assets', [FixedAssetController::class, 'index'])->name('fixed-assets.index');
        Route::get('fixed-assets/create', [FixedAssetController::class, 'create'])->name('fixed-assets.create');
        Route::post('fixed-assets', [FixedAssetController::class, 'store'])->name('fixed-assets.store');
        Route::get('fixed-assets/{asset}/edit', [FixedAssetController::class, 'edit'])->name('fixed-assets.edit');
        Route::put('fixed-assets/{asset}', [FixedAssetController::class, 'update'])->name('fixed-assets.update');
        Route::delete('fixed-assets/{asset}', [FixedAssetController::class, 'destroy'])->name('fixed-assets.destroy');
        Route::get('fixed-assets/{asset}/dispose', [AssetDisposalController::class, 'create'])->name('fixed-assets.dispose.create');
        Route::post('fixed-assets/{asset}/dispose', [AssetDisposalController::class, 'store'])->name('fixed-assets.dispose.store');
        Route::get('fixed-assets/{asset}', [FixedAssetController::class, 'show'])->name('fixed-assets.show');

        Route::get('depreciation-runs', [DepreciationRunController::class, 'index'])->name('depreciation-runs.index');
        Route::post('depreciation-runs', [DepreciationRunController::class, 'store'])->name('depreciation-runs.store');

        // §3N Financial Analysis / Reporting — unified hub linking these + the existing
        // AR/AP aging engines (§3D/§3E), not duplicating them.
        Route::get('reports', [ReportingHubController::class, 'index'])->name('reports.index');
        Route::get('reports/trial-balance', [TrialBalanceController::class, 'index'])->name('reports.trial-balance');
        Route::get('reports/trial-balance/export', [TrialBalanceController::class, 'export'])->name('reports.trial-balance.export');
        Route::get('reports/balance-sheet', [BalanceSheetController::class, 'index'])->name('reports.balance-sheet');
        Route::get('reports/balance-sheet/export', [BalanceSheetController::class, 'export'])->name('reports.balance-sheet.export');
        Route::get('reports/profit-loss', [ProfitLossController::class, 'index'])->name('reports.profit-loss');
        Route::get('reports/profit-loss/export', [ProfitLossController::class, 'export'])->name('reports.profit-loss.export');
        Route::get('reports/cash-flow', [CashFlowController::class, 'index'])->name('reports.cash-flow');
        Route::get('reports/cash-flow/export', [CashFlowController::class, 'export'])->name('reports.cash-flow.export');
        Route::get('reports/account-ledger/{account}', [AccountLedgerController::class, 'show'])->name('reports.account-ledger');
        Route::get('reports/budget-vs-actual', [BudgetVsActualController::class, 'index'])->name('reports.budget-vs-actual');

        // §3O Audit & Compliance — append-only trail, read-only view; period locking (§3O's
        // other bullet) is FiscalYearController::updatePeriodStatus() above, unchanged.
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

        // §3P Recurring Transactions — v1 ships recurring journals + recurring AR invoices
        // (see the migration docblock for why AP is deferred). RecurringGenerationService
        // (run via the scheduled sweep — routes/console.php) is what drafts documents from
        // these; nothing here posts anything.
        Route::get('recurring-journal-templates', [RecurringJournalTemplateController::class, 'index'])->name('recurring-journal-templates.index');
        Route::get('recurring-journal-templates/create', [RecurringJournalTemplateController::class, 'create'])->name('recurring-journal-templates.create');
        Route::post('recurring-journal-templates', [RecurringJournalTemplateController::class, 'store'])->name('recurring-journal-templates.store');
        Route::get('recurring-journal-templates/{template}/edit', [RecurringJournalTemplateController::class, 'edit'])->name('recurring-journal-templates.edit');
        Route::put('recurring-journal-templates/{template}', [RecurringJournalTemplateController::class, 'update'])->name('recurring-journal-templates.update');
        Route::post('recurring-journal-templates/{template}/set-active', [RecurringJournalTemplateController::class, 'setActive'])->name('recurring-journal-templates.set-active');
        Route::delete('recurring-journal-templates/{template}', [RecurringJournalTemplateController::class, 'destroy'])->name('recurring-journal-templates.destroy');

        Route::get('recurring-ar-templates', [RecurringArTemplateController::class, 'index'])->name('recurring-ar-templates.index');
        Route::get('recurring-ar-templates/create', [RecurringArTemplateController::class, 'create'])->name('recurring-ar-templates.create');
        Route::post('recurring-ar-templates', [RecurringArTemplateController::class, 'store'])->name('recurring-ar-templates.store');
        Route::get('recurring-ar-templates/{template}/edit', [RecurringArTemplateController::class, 'edit'])->name('recurring-ar-templates.edit');
        Route::put('recurring-ar-templates/{template}', [RecurringArTemplateController::class, 'update'])->name('recurring-ar-templates.update');
        Route::post('recurring-ar-templates/{template}/set-active', [RecurringArTemplateController::class, 'setActive'])->name('recurring-ar-templates.set-active');
        Route::delete('recurring-ar-templates/{template}', [RecurringArTemplateController::class, 'destroy'])->name('recurring-ar-templates.destroy');

        // §3I Cost Accounting — cost centers themselves are §3B (cost-centers.* above);
        // allocation rules/runs are the only new piece.
        Route::get('allocation-rules', [AllocationRuleController::class, 'index'])->name('allocation-rules.index');
        Route::get('allocation-rules/create', [AllocationRuleController::class, 'create'])->name('allocation-rules.create');
        Route::post('allocation-rules', [AllocationRuleController::class, 'store'])->name('allocation-rules.store');
        Route::get('allocation-rules/{rule}/edit', [AllocationRuleController::class, 'edit'])->name('allocation-rules.edit');
        Route::put('allocation-rules/{rule}', [AllocationRuleController::class, 'update'])->name('allocation-rules.update');
        Route::post('allocation-rules/{rule}/set-active', [AllocationRuleController::class, 'setActive'])->name('allocation-rules.set-active');
        Route::delete('allocation-rules/{rule}', [AllocationRuleController::class, 'destroy'])->name('allocation-rules.destroy');
        Route::get('allocation-rules/{rule}/run', [AllocationRunController::class, 'show'])->name('allocation-rules.run.show');
        Route::post('allocation-rules/{rule}/run', [AllocationRunController::class, 'store'])->name('allocation-rules.run.store');

        // §3J Budgeting — one flat annual Budget per company/fiscal year (getOrCreate'd on
        // first visit), edited one cost-center scope at a time. Budget vs. Actual (the
        // report) lives under reports/ above, next to Trial Balance et al.
        Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
        Route::post('budgets/{budget}/grid', [BudgetController::class, 'saveGrid'])->name('budgets.grid.store');
        Route::post('budgets/{budget}/import', [BudgetController::class, 'importCsv'])->name('budgets.import');

        // §3H Inventory GL Posting — the mapping admin + review queue are real and
        // browser-usable today; the events/listeners they feed have no real caller yet
        // (Inventory's own Goods Receipt/Issue/Adjustment engine isn't built — see
        // InventoryGlPostingService's docblock).
        Route::get('inventory-gl-mappings', [InventoryGlMappingController::class, 'index'])->name('inventory-gl-mappings.index');
        Route::get('inventory-gl-mappings/create', [InventoryGlMappingController::class, 'create'])->name('inventory-gl-mappings.create');
        Route::post('inventory-gl-mappings', [InventoryGlMappingController::class, 'store'])->name('inventory-gl-mappings.store');
        Route::get('inventory-gl-mappings/{mapping}/edit', [InventoryGlMappingController::class, 'edit'])->name('inventory-gl-mappings.edit');
        Route::put('inventory-gl-mappings/{mapping}', [InventoryGlMappingController::class, 'update'])->name('inventory-gl-mappings.update');
        Route::delete('inventory-gl-mappings/{mapping}', [InventoryGlMappingController::class, 'destroy'])->name('inventory-gl-mappings.destroy');

        Route::get('inventory-posting-failures', [InventoryPostingFailureController::class, 'index'])->name('inventory-posting-failures.index');
        Route::post('inventory-posting-failures/{failure}/retry', [InventoryPostingFailureController::class, 'retry'])->name('inventory-posting-failures.retry');

        // §3S Payroll GL Posting — mirrors §3H structurally (mapping admin + review queue
        // real and browser-usable today; the event/listener they feed have no real caller
        // yet — Payroll's own module is pure scaffolding, see PayrollGlPostingService).
        Route::get('payroll-component-gl-mappings', [PayrollComponentGlMappingController::class, 'index'])->name('payroll-component-gl-mappings.index');
        Route::get('payroll-component-gl-mappings/create', [PayrollComponentGlMappingController::class, 'create'])->name('payroll-component-gl-mappings.create');
        Route::post('payroll-component-gl-mappings', [PayrollComponentGlMappingController::class, 'store'])->name('payroll-component-gl-mappings.store');
        Route::get('payroll-component-gl-mappings/{mapping}/edit', [PayrollComponentGlMappingController::class, 'edit'])->name('payroll-component-gl-mappings.edit');
        Route::put('payroll-component-gl-mappings/{mapping}', [PayrollComponentGlMappingController::class, 'update'])->name('payroll-component-gl-mappings.update');
        Route::delete('payroll-component-gl-mappings/{mapping}', [PayrollComponentGlMappingController::class, 'destroy'])->name('payroll-component-gl-mappings.destroy');

        Route::get('payroll-posting-failures', [PayrollPostingFailureController::class, 'index'])->name('payroll-posting-failures.index');
        Route::post('payroll-posting-failures/{failure}/retry', [PayrollPostingFailureController::class, 'retry'])->name('payroll-posting-failures.retry');
    });
