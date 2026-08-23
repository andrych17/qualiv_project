<?php

use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\ApAgingController;
use App\Modules\Accounting\Controllers\ApBillController;
use App\Modules\Accounting\Controllers\ApDebitNoteController;
use App\Modules\Accounting\Controllers\ApPaymentController;
use App\Modules\Accounting\Controllers\ArAgingController;
use App\Modules\Accounting\Controllers\ArCreditNoteController;
use App\Modules\Accounting\Controllers\ArInvoiceController;
use App\Modules\Accounting\Controllers\ArPaymentController;
use App\Modules\Accounting\Controllers\CompanyController;
use App\Modules\Accounting\Controllers\CoretaxExportController;
use App\Modules\Accounting\Controllers\CostCenterController;
use App\Modules\Accounting\Controllers\FakturPajakBlockController;
use App\Modules\Accounting\Controllers\FiscalYearController;
use App\Modules\Accounting\Controllers\JournalController;
use App\Modules\Accounting\Controllers\TaxCodeController;
use App\Modules\Accounting\Controllers\TaxPeriodController;
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
    });
