<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementImport;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3F — CSV bank statement import, staged for future reconciliation (§3Q, not
 * built yet). MT940 is deliberately not supported — a real parser for that
 * format is meaningful effort with no consumer yet since matching doesn't exist.
 *
 * The CSV's own column order is never guessed from header names — the caller
 * supplies an explicit 0-based column map (see StoreBankStatementImportRequest),
 * so a bank whose export doesn't match some assumed layout still works. Any row
 * that fails to parse rejects the whole import (nothing persisted) rather than
 * silently staging garbage — this file crosses a real system boundary (an
 * external bank's export format), same "no silent auto-apply" posture as DMS's
 * retention actions.
 */
class BankStatementImportService
{
    private const MAX_ROWS = 2000;

    /** @param  array{date:int, description:int, amount:int, reference?:?int}  $columnMap  0-based column indexes into each CSV row */
    public function import(Company $company, BankAccount $bankAccount, UploadedFile $file, array $columnMap, int $userId): BankStatementImport
    {
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', '\\'), file($file->getRealPath()));
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows (only a header, or is empty).']);
        }

        array_shift($rows); // header row — always assumed present and skipped

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'File has more than '.self::MAX_ROWS.' data rows — split it into smaller files.']);
        }

        $parsed = $this->parseRows($rows, $columnMap);

        if (empty($parsed)) {
            throw ValidationException::withMessages(['file' => 'No data rows found after skipping blank lines.']);
        }

        return DB::transaction(function () use ($company, $bankAccount, $file, $parsed, $userId) {
            $key = $this->objectKey($company->id, $file->getClientOriginalName());
            Storage::disk('objects')->put($key, file_get_contents($file->getRealPath()));

            $import = BankStatementImport::query()->create([
                'company_id' => $company->id,
                'bank_account_id' => $bankAccount->id,
                'object_key' => $key,
                'original_filename' => $file->getClientOriginalName(),
                'line_count' => count($parsed),
                'imported_by' => $userId,
                'imported_at' => now(),
            ]);

            BankStatementLine::query()->insert(array_map(
                fn (array $line) => [...$line, 'import_id' => $import->id],
                $parsed,
            ));

            return $import->refresh();
        });
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array{date:int, description:int, amount:int, reference?:?int}  $columnMap
     * @return list<array{line_date:string, description:?string, amount:float, reference:?string, status:string, created_at:Carbon}>
     */
    private function parseRows(array $rows, array $columnMap): array
    {
        $parsed = [];
        $now = now();

        foreach ($rows as $i => $row) {
            if (empty(array_filter($row, fn ($cell) => $cell !== null && trim((string) $cell) !== ''))) {
                continue; // blank line
            }

            $rowNo = $i + 2; // +1 for 0-index, +1 for the skipped header row

            $dateRaw = trim((string) ($row[$columnMap['date']] ?? ''));
            try {
                $date = Carbon::parse($dateRaw)->toDateString();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['file' => "Row {$rowNo}: unparseable date \"{$dateRaw}\"."]);
            }

            $amountRaw = trim((string) ($row[$columnMap['amount']] ?? ''));
            $amountClean = str_replace([',', ' '], '', $amountRaw);
            if ($amountClean === '' || ! is_numeric($amountClean)) {
                throw ValidationException::withMessages(['file' => "Row {$rowNo}: unparseable amount \"{$amountRaw}\"."]);
            }

            $descRaw = $row[$columnMap['description']] ?? null;
            $refIndex = $columnMap['reference'] ?? null;
            $refRaw = $refIndex !== null ? ($row[$refIndex] ?? null) : null;

            $parsed[] = [
                'line_date' => $date,
                'description' => $descRaw !== null && trim((string) $descRaw) !== '' ? trim((string) $descRaw) : null,
                'amount' => round((float) $amountClean, 2),
                'reference' => $refRaw !== null && trim((string) $refRaw) !== '' ? trim((string) $refRaw) : null,
                'status' => BankStatementLine::STATUS_UNMATCHED,
                'created_at' => $now,
            ];
        }

        return $parsed;
    }

    private function objectKey(int $companyId, string $originalFilename): string
    {
        // Same layout convention as CoretaxExportService::objectKey() — see its docblock.
        $dir = sprintf(
            '%s/ACCOUNTING/%s/bank_statements/%s/%s',
            tenant()?->id ?? 'local',
            $companyId,
            now()->format('Y'),
            now()->format('m'),
        );

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFilename) ?? 'statement.csv';

        return "{$dir}/".now()->format('YmdHis').'-'.substr((string) Str::uuid(), 0, 8)."-{$safeName}";
    }
}
