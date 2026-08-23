<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use Illuminate\Validation\ValidationException;

/** §3B Chart of Accounts. */
class AccountService
{
    /**
     * §3B: "a starter Indonesian-standard COA ships with the module so a new company
     * isn't starting from a blank sheet." Deliberately compact (not exhaustive) —
     * enough control accounts (AR/AP) and one account per statutory group to post a
     * real journal from day one; a company can freely add/rename from here.
     *
     * @var list<array{code:string, name:string, type:string, balance:string, control?:bool}>
     */
    private const STARTER_COA = [
        ['code' => '10100', 'name' => 'Kas', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '10200', 'name' => 'Bank', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '11000', 'name' => 'Piutang Usaha', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT, 'control' => true],
        // Claimable input PPN (§3E) — debit-normal asset, distinct from '22000 Utang Pajak'
        // (a liability, used for PPN Output/PPh Payable). Without this, a fresh company can
        // only point an input tax code at a wrong-sided account.
        ['code' => '11500', 'name' => 'PPN Masukan', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '13000', 'name' => 'Persediaan', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT, 'control' => true],
        ['code' => '15000', 'name' => 'Aset Tetap', 'type' => Account::TYPE_ASSET, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '21000', 'name' => 'Utang Usaha', 'type' => Account::TYPE_LIABILITY, 'balance' => Account::BALANCE_CREDIT, 'control' => true],
        ['code' => '22000', 'name' => 'Utang Pajak', 'type' => Account::TYPE_LIABILITY, 'balance' => Account::BALANCE_CREDIT],
        ['code' => '31000', 'name' => 'Modal', 'type' => Account::TYPE_EQUITY, 'balance' => Account::BALANCE_CREDIT],
        ['code' => '32000', 'name' => 'Laba Ditahan', 'type' => Account::TYPE_EQUITY, 'balance' => Account::BALANCE_CREDIT],
        ['code' => '41000', 'name' => 'Pendapatan Usaha', 'type' => Account::TYPE_REVENUE, 'balance' => Account::BALANCE_CREDIT],
        ['code' => '51000', 'name' => 'Harga Pokok Penjualan', 'type' => Account::TYPE_COGS, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '61000', 'name' => 'Beban Operasional', 'type' => Account::TYPE_EXPENSE, 'balance' => Account::BALANCE_DEBIT],
        ['code' => '62000', 'name' => 'Beban Administrasi & Umum', 'type' => Account::TYPE_EXPENSE, 'balance' => Account::BALANCE_DEBIT],
    ];

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Account
    {
        $this->assertParentSameCompany($data['company_id'], $data['parent_account_id'] ?? null);

        return Account::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Account $account, array $data): Account
    {
        $this->assertParentSameCompany(
            $data['company_id'] ?? $account->company_id,
            $data['parent_account_id'] ?? $account->parent_account_id,
            $account->id,
        );

        $account->update($data);

        return $account->refresh();
    }

    public function delete(Account $account): void
    {
        if ($account->children()->exists()) {
            throw ValidationException::withMessages(['account' => 'This account has child accounts — reassign or delete them first.']);
        }

        // Journal lines FK to accounts without cascade, so a real posting history
        // already blocks this at the DB level — this check just gives a readable message.
        if ($account->glJournalLines()->exists()) {
            throw ValidationException::withMessages(['account' => 'This account has posted journal activity and cannot be deleted.']);
        }

        $account->delete();
    }

    /** No-op if the company already has any accounts — never overwrites a company that's already been set up. */
    public function seedStarterCoa(Company $company): void
    {
        if (Account::query()->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages(['account' => 'This company already has accounts — the starter COA is only for a fresh company.']);
        }

        $arControlAccountId = null;
        $apControlAccountId = null;

        foreach (self::STARTER_COA as $row) {
            $account = Account::query()->create([
                'company_id' => $company->id,
                'account_code' => $row['code'],
                'account_name' => $row['name'],
                'account_type' => $row['type'],
                'normal_balance' => $row['balance'],
                'is_control_account' => $row['control'] ?? false,
            ]);

            // §3D/§3E each need a designated control account to post against — '11000
            // Piutang Usaha' / '21000 Utang Usaha' are the starter COA's ones, so a fresh
            // company isn't immediately broken on its first invoice or bill.
            if ($row['code'] === '11000') {
                $arControlAccountId = $account->id;
            }
            if ($row['code'] === '21000') {
                $apControlAccountId = $account->id;
            }
        }

        $company->update([
            'coa_template_code' => 'ID_STANDARD',
            'ar_control_account_id' => $arControlAccountId,
            'ap_control_account_id' => $apControlAccountId,
        ]);
    }

    private function assertParentSameCompany(int $companyId, ?int $parentAccountId, ?int $excludeId = null): void
    {
        if ($parentAccountId === null) {
            return;
        }

        if ($parentAccountId === $excludeId) {
            throw ValidationException::withMessages(['parent_account_id' => 'An account cannot be its own parent.']);
        }

        $parent = Account::query()->find($parentAccountId);
        if ($parent === null || $parent->company_id !== $companyId) {
            throw ValidationException::withMessages(['parent_account_id' => 'The selected parent account is invalid.']);
        }
    }
}
