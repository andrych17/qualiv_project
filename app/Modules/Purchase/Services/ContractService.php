<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\WorkflowEngine\Exceptions\WorkflowEngineException;
use App\Modules\WorkflowEngine\Services\WorkflowService;

class ContractService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): PurContractHdr
    {
        return PurContractHdr::create([
            'supplier_id' => $data['supplier_id'],
            'title' => $data['title'],
            'type' => $data['type'] ?? PurContractHdr::TYPE_PROJECT,
            'value' => $data['value'] ?? null,
            'currency_code' => $data['currency_code'] ?? 'IDR',
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'auto_renew' => $data['auto_renew'] ?? false,
            'notice_period_days' => $data['notice_period_days'] ?? 30,
            'dms_document_id' => $data['dms_document_id'] ?? null,
            'status' => PurContractHdr::STATUS_DRAFT,
            'created_by' => $userId,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(PurContractHdr $contract, array $data): PurContractHdr
    {
        $contract->update([
            'supplier_id' => $data['supplier_id'] ?? $contract->supplier_id,
            'title' => $data['title'] ?? $contract->title,
            'type' => $data['type'] ?? $contract->type,
            'value' => array_key_exists('value', $data) ? $data['value'] : $contract->value,
            'currency_code' => $data['currency_code'] ?? $contract->currency_code,
            'start_date' => $data['start_date'] ?? $contract->start_date,
            'end_date' => $data['end_date'] ?? $contract->end_date,
            'auto_renew' => array_key_exists('auto_renew', $data) ? (bool) $data['auto_renew'] : $contract->auto_renew,
            'notice_period_days' => $data['notice_period_days'] ?? $contract->notice_period_days,
            'dms_document_id' => array_key_exists('dms_document_id', $data) ? $data['dms_document_id'] : $contract->dms_document_id,
        ]);

        return $contract->fresh(['supplier', 'creator']);
    }

    public function activate(PurContractHdr $contract): PurContractHdr
    {
        $contract->status = PurContractHdr::STATUS_ACTIVE;
        $contract->save();

        return $contract->refresh();
    }

    public function terminate(PurContractHdr $contract): PurContractHdr
    {
        $contract->status = PurContractHdr::STATUS_TERMINATED;
        $contract->save();

        return $contract->refresh();
    }

    public function renew(PurContractHdr $contract, string $newEndDate, ?float $newValue = null): PurContractHdr
    {
        $contract->end_date = $newEndDate;
        if ($newValue !== null) {
            $contract->value = $newValue;
        }
        $contract->status = PurContractHdr::STATUS_RENEWED;
        $contract->save();

        return $contract->refresh();
    }

    /**
     * Computes the spend committed against this contract across active POs (§3H).
     */
    public function calculateSpend(PurContractHdr $contract): float
    {
        return (float) PurOrderHdr::query()
            ->where('supplier_id', $contract->supplier_id)
            ->whereBetween('created_at', [
                $contract->start_date->startOfDay(),
                $contract->end_date->endOfDay(),
            ])
            ->whereNotIn('status', [PurOrderHdr::STATUS_CANCELLED])
            ->sum('total_amount');
    }

    /**
     * Scans active contracts expiring within their notice period (§3H).
     */
    public function scanExpiringContracts(): int
    {
        $contracts = PurContractHdr::query()
            ->where('status', PurContractHdr::STATUS_ACTIVE)
            ->get();

        $count = 0;
        foreach ($contracts as $contract) {
            $noticeDays = $contract->notice_period_days ?? 30;
            $expirationThreshold = now()->addDays($noticeDays)->toDateString();

            if ($contract->end_date->toDateString() <= $expirationThreshold) {
                $contract->status = PurContractHdr::STATUS_EXPIRING_SOON;
                $contract->save();

                // Trigger WNE alert if available
                try {
                    if (class_exists(WorkflowService::class) && app()->bound(WorkflowService::class)) {
                        app(WorkflowService::class)->start(
                            'purchase.contract_expiring',
                            'purchase.pur_contract_hdrs',
                            $contract->id,
                            $contract->created_by
                        );
                    }
                } catch (WorkflowEngineException) {
                    // Graceful fallback
                }

                $count++;
            }
        }

        return $count;
    }
}
