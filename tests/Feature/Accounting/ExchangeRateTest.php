<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3L Multi Currency — exchange rate CRUD and the rateFor() lookup every future foreign-currency posting will resolve through. */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_an_exchange_rate(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/exchange-rates?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ExchangeRates/Index')->where('baseCurrency', 'IDR'));
        $this->get("/accounting/exchange-rates/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ExchangeRates/Create'));

        $this->post('/accounting/exchange-rates', [
            'company_id' => $companyId, 'currency_code' => 'USD', 'rate_to_base' => 15500, 'effective_date' => '2026-01-01',
        ])->assertRedirect(route('accounting.exchange-rates.index', ['company_id' => $companyId]));

        $rateId = null;
        $tenant->run(function () use (&$rateId, $companyId) {
            $rateId = ExchangeRate::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/exchange-rates/{$rateId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('exchangeRate.rate_to_base', 15500));

        $this->put("/accounting/exchange-rates/{$rateId}", ['rate_to_base' => 15600, 'effective_date' => '2026-01-01'])
            ->assertRedirect(route('accounting.exchange-rates.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($rateId) {
            $this->assertEqualsWithDelta(15600.0, (float) ExchangeRate::query()->find($rateId)->rate_to_base, 0.001);
        });

        $this->delete("/accounting/exchange-rates/{$rateId}")->assertRedirect(route('accounting.exchange-rates.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($rateId) {
            $this->assertNull(ExchangeRate::query()->find($rateId));
        });
    }

    public function test_store_rejects_disabled_currency_base_currency_and_duplicate_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'is_enabled' => false]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01']);
        });

        $this->post('/accounting/exchange-rates', [
            'company_id' => $companyId, 'currency_code' => 'EUR', 'rate_to_base' => 16000, 'effective_date' => '2026-01-01',
        ])->assertSessionHasErrors(['currency_code']);

        $this->post('/accounting/exchange-rates', [
            'company_id' => $companyId, 'currency_code' => 'IDR', 'rate_to_base' => 1, 'effective_date' => '2026-01-01',
        ])->assertSessionHasErrors(['currency_code']);

        $this->post('/accounting/exchange-rates', [
            'company_id' => $companyId, 'currency_code' => 'USD', 'rate_to_base' => 16000, 'effective_date' => '2026-01-01',
        ])->assertSessionHasErrors(['effective_date']);

        $this->post('/accounting/exchange-rates', [
            'company_id' => 999999, 'currency_code' => 'USD', 'rate_to_base' => 16000, 'effective_date' => '2026-02-01',
        ])->assertSessionHasErrors(['company_id']);
    }

    public function test_update_rejects_a_duplicate_date_excluding_itself(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $rateAId, $rateBId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$rateAId, &$rateBId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $rateAId = $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01'])->id;
            $rateBId = $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-02-01'])->id;
        });

        $this->put("/accounting/exchange-rates/{$rateBId}", ['rate_to_base' => 16000, 'effective_date' => '2026-01-01'])
            ->assertSessionHasErrors(['effective_date']);

        // Re-saving rate B with its own existing date is fine (excludes itself).
        $this->put("/accounting/exchange-rates/{$rateBId}", ['rate_to_base' => 16000, 'effective_date' => '2026-02-01'])
            ->assertSessionDoesntHaveErrors();
    }

    public function test_exchange_rate_index_filters_by_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeExchangeRate($company);
        });

        $this->get("/accounting/exchange-rates?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rates', 1));
    }

    public function test_rate_for_short_circuits_the_base_currency(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany(['base_currency' => 'IDR']);
            $rate = app(ExchangeRateService::class)->rateFor($company, 'IDR', now()->toDateString());
            $this->assertSame(1.0, $rate);
        });
    }

    public function test_rate_for_finds_the_most_recent_rate_on_or_before_the_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-03-01', 'rate_to_base' => 15600]);

            $service = app(ExchangeRateService::class);
            $this->assertEqualsWithDelta(15000.0, $service->rateFor($company, 'USD', '2026-02-15'), 0.001);
            $this->assertEqualsWithDelta(15600.0, $service->rateFor($company, 'USD', '2026-06-01'), 0.001);
        });
    }

    public function test_rate_for_throws_when_no_rate_exists_on_or_before_the_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-06-01']);

            $this->expectException(ValidationException::class);
            app(ExchangeRateService::class)->rateFor($company, 'USD', '2026-01-01');
        });
    }
}
