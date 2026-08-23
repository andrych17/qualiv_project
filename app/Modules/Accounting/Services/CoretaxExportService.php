<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Contracts\CoretaxExportDriverInterface;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CoretaxExportBatch;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** §3M — generates a Coretax-import XML batch for one company/period/batch type and logs it. */
class CoretaxExportService
{
    public function __construct(protected CoretaxExportDriverInterface $driver) {}

    public function generate(Company $company, TaxPeriod $period, string $batchType, int $generatedBy): CoretaxExportBatch
    {
        if ($period->company_id !== $company->id) {
            throw ValidationException::withMessages(['tax_period_id' => 'The selected tax period does not belong to this company.']);
        }

        $records = $this->recordsFor($company->id, $period, $batchType);
        $xml = $this->driver->export($company, $period, $batchType, $records);

        $key = $this->objectKey($company->id, $batchType);
        Storage::disk('objects')->put($key, $xml);

        return CoretaxExportBatch::query()->create([
            'company_id' => $company->id,
            'batch_type' => $batchType,
            'tax_period_id' => $period->id,
            'object_key' => $key,
            'record_count' => $records->count(),
            'generated_by' => $generatedBy,
            'generated_at' => now(),
        ]);
    }

    /** @return Collection<int, TaxFakturPajak|TaxBuktiPotong> */
    private function recordsFor(int $companyId, TaxPeriod $period, string $batchType): Collection
    {
        return match ($batchType) {
            CoretaxExportBatch::TYPE_FAKTUR_KELUARAN => TaxFakturPajak::query()
                ->where('company_id', $companyId)
                ->where('direction', TaxFakturPajak::DIRECTION_OUTPUT)
                ->where('status', TaxFakturPajak::STATUS_ISSUED)
                ->whereRaw("to_char(issued_at, 'YYYY-MM') = ?", [$period->masa_pajak])
                ->get(),
            CoretaxExportBatch::TYPE_FAKTUR_MASUKAN => TaxFakturPajak::query()
                ->where('company_id', $companyId)
                ->where('direction', TaxFakturPajak::DIRECTION_INPUT)
                ->where('status', TaxFakturPajak::STATUS_ISSUED)
                ->whereRaw("to_char(issued_at, 'YYYY-MM') = ?", [$period->masa_pajak])
                ->get(),
            CoretaxExportBatch::TYPE_BUKTI_POTONG => TaxBuktiPotong::query()
                ->where('company_id', $companyId)
                ->where('status', TaxBuktiPotong::STATUS_ISSUED)
                ->whereRaw("to_char(issued_at, 'YYYY-MM') = ?", [$period->masa_pajak])
                ->get(),
            default => throw new \InvalidArgumentException("Unknown Coretax batch type '{$batchType}'."),
        };
    }

    private function objectKey(int $companyId, string $batchType): string
    {
        // Object key layout per CLAUDE.md §7B / ACCOUNTING_SPECS.md §4 — bare tenant id
        // (not "tenant_{id}"), same precedent DMS\Services\DocumentService already uses.
        $dir = sprintf(
            '%s/ACCOUNTING/%s/tax_documents/%s/%s',
            tenant()?->id ?? 'local',
            $companyId,
            now()->format('Y'),
            now()->format('m'),
        );

        return "{$dir}/{$batchType}-".now()->format('YmdHis').'-'.substr((string) Str::uuid(), 0, 8).'.xml';
    }
}
