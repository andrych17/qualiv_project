<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\BudgetCategoryAccount;
use Illuminate\Validation\ValidationException;

/**
 * §3B — tenant-editable category → GL account mapping. "Mapping a category to a GL account is
 * optional and additive" — a tenant can run Budgeting on manual actuals forever and adopt this
 * category by category with no schema change (§3B "Rules / logic").
 *
 * Same NULL-unsafe-index caveat as TargetService/KpiValueService: Postgres treats every NULL
 * `company_id` as distinct, so a duplicate company-agnostic mapping for the same
 * category+account wouldn't be caught by a DB unique index alone.
 */
class BudgetCategoryAccountService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): BudgetCategoryAccount
    {
        $this->assertUnique($data);

        return BudgetCategoryAccount::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(BudgetCategoryAccount $mapping, array $data): BudgetCategoryAccount
    {
        $this->assertUnique($data, excludeId: $mapping->id);

        $mapping->update($this->attributes($data));

        return $mapping->refresh();
    }

    public function delete(BudgetCategoryAccount $mapping): void
    {
        $mapping->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function assertUnique(array $data, ?int $excludeId = null): void
    {
        $exists = BudgetCategoryAccount::query()
            ->where('category', $data['category'])
            ->where('account_id', $data['account_id'])
            ->where('company_id', $data['company_id'] ?? null)
            ->when($excludeId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['account_id' => 'This category is already mapped to this account (and company scope).']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'category' => $data['category'],
            'account_id' => $data['account_id'],
            'company_id' => $data['company_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
