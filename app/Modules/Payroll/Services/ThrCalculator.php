<?php

namespace App\Modules\Payroll\Services;

use Carbon\Carbon;

/**
 * Tunjangan Hari Raya (THR) Religious Holiday Bonus Calculator per PP 36/2021 & Permenaker 6/2016.
 */
class ThrCalculator
{
    /**
     * Calculate THR entitlement amount based on hire date and cut-off date.
     *
     * Rules:
     * - Tenure >= 12 months: 100% (1 month salary)
     * - 1 month <= Tenure < 12 months: (tenure_months / 12) * salary
     * - Tenure < 1 month: 0
     */
    public function calculate(float $monthlySalary, string|Carbon $hireDate, string|Carbon $cutoffDate): array
    {
        $hire = $hireDate instanceof Carbon ? $hireDate : Carbon::parse($hireDate);
        $cutoff = $cutoffDate instanceof Carbon ? $cutoffDate : Carbon::parse($cutoffDate);

        if ($cutoff->lt($hire) || $monthlySalary <= 0) {
            return [
                'tenure_months' => 0,
                'is_prorated' => false,
                'factor' => 0.0,
                'thr_amount' => 0.0,
            ];
        }

        $diffDays = $hire->diffInDays($cutoff);
        $tenureMonths = $diffDays / 30.4375; // Average days per month

        if ($tenureMonths >= 12) {
            return [
                'tenure_months' => round($tenureMonths, 1),
                'is_prorated' => false,
                'factor' => 1.0,
                'thr_amount' => round($monthlySalary, 2),
            ];
        }

        if ($tenureMonths >= 1) {
            $factor = $tenureMonths / 12.0;
            return [
                'tenure_months' => round($tenureMonths, 1),
                'is_prorated' => true,
                'factor' => round($factor, 4),
                'thr_amount' => round($monthlySalary * $factor, 2),
            ];
        }

        return [
            'tenure_months' => round($tenureMonths, 1),
            'is_prorated' => true,
            'factor' => 0.0,
            'thr_amount' => 0.0,
        ];
    }
}
