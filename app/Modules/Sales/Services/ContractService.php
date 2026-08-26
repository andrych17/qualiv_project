<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Events\ContractExpiring;
use App\Modules\Sales\Events\ContractRenewed;
use App\Modules\Sales\Models\Contract;
use App\Modules\Sales\Models\ContractSubscription;
use App\Modules\Sales\Models\RecurringBillingSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractService
{
    /**
     * Create draft contract with subscriptions.
     */
    public function create(array $data, ?int $userId): Contract
    {
        return DB::transaction(function () use ($data, $userId) {
            $contract = Contract::create([
                'customer_id' => $data['customer_id'],
                'name' => $data['name'],
                'term_start' => $data['term_start'],
                'term_end' => $data['term_end'],
                'auto_renew' => $data['auto_renew'] ?? false,
                'status' => Contract::STATUS_DRAFT,
                'price_list_id' => $data['price_list_id'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncSubscriptions($contract, $data['subscriptions'] ?? []);

            return $contract->load(['subscriptions', 'customer', 'priceList']);
        });
    }

    /**
     * Update draft contract.
     */
    public function update(Contract $contract, array $data): Contract
    {
        if ($contract->status !== Contract::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft contracts can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($contract, $data) {
            $contract->update([
                'customer_id' => $data['customer_id'] ?? $contract->customer_id,
                'name' => $data['name'] ?? $contract->name,
                'term_start' => $data['term_start'] ?? $contract->term_start,
                'term_end' => $data['term_end'] ?? $contract->term_end,
                'auto_renew' => $data['auto_renew'] ?? $contract->auto_renew,
                'price_list_id' => $data['price_list_id'] ?? $contract->price_list_id,
            ]);

            if (isset($data['subscriptions'])) {
                $contract->subscriptions()->delete();
                $this->syncSubscriptions($contract, $data['subscriptions']);
            }

            return $contract->refresh()->load(['subscriptions', 'customer', 'priceList']);
        });
    }

    /**
     * Activate contract and seed recurring billing schedules (§3L).
     */
    public function activate(Contract $contract): Contract
    {
        if ($contract->status !== Contract::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft contracts can be activated.'],
            ]);
        }

        return DB::transaction(function () use ($contract) {
            $contract->load('subscriptions');

            if ($contract->subscriptions->isEmpty()) {
                throw ValidationException::withMessages([
                    'subscriptions' => ['Contract must have at least one recurring subscription line to activate.'],
                ]);
            }

            // Seed recurring billing schedules (one per subscription line)
            foreach ($contract->subscriptions as $sub) {
                RecurringBillingSchedule::create([
                    'contr_subscription_id' => $sub->id,
                    'customer_id' => $contract->customer_id,
                    'next_bill_date' => $contract->term_start,
                    'is_active' => true,
                ]);
            }

            $contract->update(['status' => Contract::STATUS_ACTIVE]);

            return $contract;
        });
    }

    /**
     * Cancel contract forward-only (§3L).
     */
    public function cancel(Contract $contract): Contract
    {
        return DB::transaction(function () use ($contract) {
            // Deactivate all future recurring schedules
            foreach ($contract->subscriptions as $sub) {
                $sub->recurringSchedules()->update(['is_active' => false]);
            }

            $contract->update(['status' => Contract::STATUS_CANCELLED]);

            return $contract;
        });
    }

    /**
     * Renew contract for another term.
     */
    public function renew(Contract $contract, string $newTermEnd): Contract
    {
        $contract->update([
            'term_end' => $newTermEnd,
            'status' => Contract::STATUS_RENEWED,
        ]);

        event(new ContractRenewed($contract));

        return $contract;
    }

    private function syncSubscriptions(Contract $contract, array $subscriptions): void
    {
        $lineNo = 1;
        foreach ($subscriptions as $sub) {
            $contract->subscriptions()->create([
                'line_no' => $lineNo++,
                'item_type' => $sub['item_type'] ?? 'service',
                'product_id' => $sub['product_id'] ?? null,
                'description' => $sub['description'],
                'recurring_amount' => (float) $sub['recurring_amount'],
                'currency' => $sub['currency'] ?? 'IDR',
                'billing_interval' => $sub['billing_interval'] ?? ContractSubscription::INTERVAL_MONTHLY,
                'is_active' => true,
            ]);
        }
    }
}
