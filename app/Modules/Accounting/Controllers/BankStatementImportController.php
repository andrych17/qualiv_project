<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementImport;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreBankStatementImportRequest;
use App\Modules\Accounting\Services\BankStatementImportService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3F — CSV bank statement staging. Matching/reconciliation is §3Q, not built — this only stages lines for that future workflow (see BankStatementImportService docblock). */
class BankStatementImportController extends Controller
{
    public function __construct(private readonly BankStatementImportService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $imports = BankStatementImport::query()
            ->where('company_id', $companyId)
            ->with('bankAccount:id,name')
            ->orderByDesc('imported_at')
            ->get();

        return Inertia::render('Accounting/BankStatementImports/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'imports' => $imports->map(fn (BankStatementImport $i) => [
                'id' => $i->id,
                'bank_account_name' => $i->bankAccount?->name,
                'original_filename' => $i->original_filename,
                'line_count' => $i->line_count,
                'imported_at' => $i->imported_at->toDateTimeString(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/BankStatementImports/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'bankAccounts' => $this->bankAccountOptions($companyId),
        ]);
    }

    public function store(StoreBankStatementImportRequest $request)
    {
        $data = $request->validated();
        $company = Company::query()->findOrFail($data['company_id']);
        $bankAccount = BankAccount::query()->findOrFail($data['bank_account_id']);

        $import = $this->service->import(
            $company,
            $bankAccount,
            $request->file('file'),
            array_filter([
                'date' => $data['date_column'],
                'description' => $data['description_column'],
                'amount' => $data['amount_column'],
                'reference' => $data['reference_column'] ?? null,
            ], fn ($v) => $v !== null),
            $request->user()->id,
        );

        return redirect()->route('accounting.bank-statement-imports.show', $import->id)
            ->with('success', "Imported {$import->line_count} statement lines.");
    }

    public function show(BankStatementImport $bankStatementImport): Response
    {
        $bankStatementImport->load('bankAccount:id,name,currency_code');
        $lines = BankStatementLine::query()->where('import_id', $bankStatementImport->id)->orderBy('line_date')->orderBy('id')->get();

        return Inertia::render('Accounting/BankStatementImports/Show', [
            'import' => [
                'id' => $bankStatementImport->id,
                'bank_account_name' => $bankStatementImport->bankAccount?->name,
                'currency_code' => $bankStatementImport->bankAccount?->currency_code,
                'original_filename' => $bankStatementImport->original_filename,
                'line_count' => $bankStatementImport->line_count,
                'imported_at' => $bankStatementImport->imported_at->toDateTimeString(),
            ],
            'lines' => $lines->map(fn (BankStatementLine $l) => [
                'id' => $l->id,
                'line_date' => $l->line_date->toDateString(),
                'description' => $l->description,
                'amount' => (float) $l->amount,
                'reference' => $l->reference,
                'status' => $l->status,
            ]),
        ]);
    }

    private function bankAccountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return BankAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'currency_code'])
            ->map(fn (BankAccount $b) => ['value' => $b->id, 'label' => "{$b->name} ({$b->currency_code})"])
            ->values()
            ->all();
    }
}
