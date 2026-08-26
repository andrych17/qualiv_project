<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\EmploymentContract;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractService
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return EmploymentContract::query()
            ->with(['employee.position.job', 'employee.position.orgUnit'])
            ->filter($filters)
            ->orderByDesc('start_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): EmploymentContract
    {
        $this->validateCompliance($data);

        return EmploymentContract::create($data);
    }

    public function renew(EmploymentContract $oldContract, array $newData): EmploymentContract
    {
        return DB::transaction(function () use ($oldContract, $newData) {
            $newData['employee_id'] = $oldContract->employee_id;
            $newData['renewed_from_contract_id'] = $oldContract->id;
            $newData['status'] = EmploymentContract::STATUS_ACTIVE;

            $this->validateCompliance($newData);

            $oldContract->update(['status' => EmploymentContract::STATUS_RENEWED]);

            return EmploymentContract::create($newData);
        });
    }

    public function terminate(EmploymentContract $contract): EmploymentContract
    {
        $contract->update(['status' => EmploymentContract::STATUS_TERMINATED]);

        return $contract;
    }

    public function getExpiringContracts(int $days = 60): Collection
    {
        $threshold = Carbon::now()->addDays($days)->toDateString();

        return EmploymentContract::query()
            ->with(['employee.position.job', 'employee.position.orgUnit'])
            ->where('status', EmploymentContract::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $threshold)
            ->orderBy('end_date')
            ->get();
    }

    /**
     * Indonesian Labor Law Compliance:
     * - PKWT cannot have probation (PP 35/2021).
     * - PKWT must have end_date.
     * - PKWT total continuous duration cannot exceed 5 years.
     */
    protected function validateCompliance(array $data): void
    {
        if (($data['contract_type'] ?? null) === EmploymentContract::TYPE_PKWT) {
            if (empty($data['end_date'])) {
                throw ValidationException::withMessages(['end_date' => 'PKWT contract requires an end date.']);
            }

            if (! empty($data['probation_end_date'])) {
                throw ValidationException::withMessages(['probation_end_date' => 'PKWT contract cannot have a probation period.']);
            }

            // Total PKWT continuous duration check (5-year limit per PP 35/2021)
            $totalDays = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date']));
            $ancestorId = $data['renewed_from_contract_id'] ?? null;

            while ($ancestorId) {
                $ancestor = EmploymentContract::find($ancestorId);
                if ($ancestor && $ancestor->contract_type === EmploymentContract::TYPE_PKWT) {
                    $totalDays += Carbon::parse($ancestor->start_date)->diffInDays(Carbon::parse($ancestor->end_date));
                    $ancestorId = $ancestor->renewed_from_contract_id;
                } else {
                    break;
                }
            }

            if ($totalDays > 1826) { // ~5 years
                throw ValidationException::withMessages(['end_date' => 'Total cumulative PKWT duration cannot exceed 5 years (PP 35/2021).']);
            }
        }
    }
}
