<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3L — rate lookup and plain CRUD for a company's exchange rates.
 *
 * rateFor() is the one place AR/AP posting resolves a conversion rate. A
 * base-currency transaction short-circuits to 1.0 without touching the table
 * at all — every tenant transacting only in their own base currency (the
 * common case today, since no module yet generates a real foreign-currency
 * document) posts byte-identical to before this feature existed, with no
 * backfill required.
 */
class ExchangeRateService
{
    /** Base-currency transactions never need a rate row — this is the compatibility short-circuit. */
    public function rateFor(Company $company, string $currencyCode, string $date): float
    {
        if ($currencyCode === $company->base_currency) {
            return 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where('currency_code', $currencyCode)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        if ($rate === null) {
            throw ValidationException::withMessages([
                'currency_code' => "No exchange rate for {$currencyCode} effective on or before {$date} — add one before posting a {$currencyCode} document for {$company->legal_name}.",
            ]);
        }

        return (float) $rate->rate_to_base;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): ExchangeRate
    {
        return ExchangeRate::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(ExchangeRate $rate, array $data): ExchangeRate
    {
        return DB::transaction(function () use ($rate, $data) {
            $before = $rate->toArray();
            $rate->update($data);

            AuditLog::record([
                'company_id' => $rate->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.exchange_rates',
                'subject_id' => $rate->id,
                'before_snapshot' => $before,
                'after_snapshot' => $rate->toArray(),
            ]);

            return $rate->refresh();
        });
    }

    public function delete(ExchangeRate $rate): void
    {
        DB::transaction(function () use ($rate) {
            AuditLog::record([
                'company_id' => $rate->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.exchange_rates',
                'subject_id' => $rate->id,
                'before_snapshot' => $rate->toArray(),
            ]);

            $rate->delete();
        });
    }
}
