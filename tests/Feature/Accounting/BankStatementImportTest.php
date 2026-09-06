<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementImport;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Services\BankStatementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F CSV bank statement import — staged for future reconciliation (§3Q, not built in this phase). */
class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private const CSV = "Date,Description,Amount,Reference\n2026-01-05,Customer payment,500000,REF-1\n2026-01-06,Bank fee,-15000,REF-2\n";

    public function test_admin_can_import_a_csv_bank_statement(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $bankAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$bankAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccountId = BankAccount::query()->create(['company_id' => $companyId, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id])->id;
        });

        $this->get("/accounting/bank-statement-imports?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankStatementImports/Index'));
        $this->get("/accounting/bank-statement-imports/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankStatementImports/Create'));
        // No company_id query param — BankStatementImportController::bankAccountOptions()'s early-return branch.
        $this->get('/accounting/bank-statement-imports/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('bankAccounts', []));

        $file = UploadedFile::fake()->createWithContent('statement.csv', self::CSV);

        $this->post('/accounting/bank-statement-imports', [
            'company_id' => $companyId, 'bank_account_id' => $bankAccountId, 'file' => $file,
            'date_column' => 0, 'description_column' => 1, 'amount_column' => 2, 'reference_column' => 3,
        ])->assertRedirect();

        $importId = null;
        $tenant->run(function () use (&$importId, $companyId) {
            $import = BankStatementImport::query()->where('company_id', $companyId)->first();
            $importId = $import->id;
            $this->assertSame(2, $import->line_count);

            $lines = BankStatementLine::query()->where('import_id', $importId)->orderBy('line_date')->get();
            $this->assertEqualsWithDelta(500000.0, (float) $lines[0]->amount, 0.01);
            $this->assertSame('REF-1', $lines[0]->reference);
            $this->assertEqualsWithDelta(-15000.0, (float) $lines[1]->amount, 0.01);
            $this->assertSame(BankStatementLine::STATUS_UNMATCHED, $lines[0]->status);
        });

        $this->get("/accounting/bank-statement-imports/{$importId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankStatementImports/Show')->has('lines', 2));
    }

    public function test_store_rejects_invalid_company_and_bank_account(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $file = UploadedFile::fake()->createWithContent('statement.csv', self::CSV);

        $this->post('/accounting/bank-statement-imports', [
            'company_id' => 999999, 'bank_account_id' => 999999, 'file' => $file,
            'date_column' => 0, 'description_column' => 1, 'amount_column' => 2,
        ])->assertSessionHasErrors(['company_id', 'bank_account_id']);
    }

    public function test_import_rejects_a_file_exceeding_the_max_row_count(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);

            $csv = "Date,Description,Amount\n";
            for ($i = 0; $i < 2001; $i++) {
                $csv .= "2026-01-05,Row {$i},100\n";
            }
            $file = UploadedFile::fake()->createWithContent('too_many_rows.csv', $csv);

            $this->expectException(ValidationException::class);
            app(BankStatementImportService::class)->import($company, $bankAccount, $file, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
        });
    }

    public function test_import_rejects_a_file_with_only_blank_lines_after_the_header(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $file = UploadedFile::fake()->createWithContent('blank_rows.csv', "Date,Description,Amount\n\n\n");

            $this->expectException(ValidationException::class);
            app(BankStatementImportService::class)->import($company, $bankAccount, $file, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
        });
    }

    public function test_import_rejects_a_file_with_no_data_rows(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);

            $headerOnly = UploadedFile::fake()->createWithContent('empty.csv', "Date,Description,Amount,Reference\n");
            $this->expectException(ValidationException::class);
            app(BankStatementImportService::class)->import($company, $bankAccount, $headerOnly, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
        });
    }

    public function test_import_rejects_an_unparseable_date_and_amount(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $svc = app(BankStatementImportService::class);

            $badDate = UploadedFile::fake()->createWithContent('bad_date.csv', "Date,Description,Amount\nnotadate,X,100\n");
            try {
                $svc->import($company, $bankAccount, $badDate, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
                $this->fail('Expected a ValidationException for unparseable date.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }

            $badAmount = UploadedFile::fake()->createWithContent('bad_amount.csv', "Date,Description,Amount\n2026-01-05,X,notanumber\n");
            try {
                $svc->import($company, $bankAccount, $badAmount, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
                $this->fail('Expected a ValidationException for unparseable amount.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        });
    }

    public function test_import_skips_blank_lines_and_works_without_a_reference_column(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);

            $csvWithBlankLine = "Date,Description,Amount\n2026-01-05,X,100\n\n2026-01-06,Y,200\n";
            $file = UploadedFile::fake()->createWithContent('with_blank.csv', $csvWithBlankLine);

            $import = app(BankStatementImportService::class)->import($company, $bankAccount, $file, ['date' => 0, 'description' => 1, 'amount' => 2], $this->adminUserId());
            $this->assertSame(2, $import->line_count);
            $this->assertNull(BankStatementLine::query()->where('import_id', $import->id)->first()->reference);
        });
    }
}
