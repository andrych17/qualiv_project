<?php

namespace App\Modules\Payroll\Services;

use Carbon\Carbon;

/**
 * Indonesian Statutory Severance (Uang Pesangon, UPMK, UPH) Calculator per PP 35/2021 & UU Cipta Kerja.
 */
class SeveranceCalculator
{
    /**
     * Calculate statutory termination pay package.
     */
    public function calculate(float $monthlySalary, string|Carbon $hireDate, string|Carbon $terminationDate, string $reason = 'resignation'): array
    {
        $hire = $hireDate instanceof Carbon ? $hireDate : Carbon::parse($hireDate);
        $term = $terminationDate instanceof Carbon ? $terminationDate : Carbon::parse($terminationDate);

        if ($term->lt($hire) || $monthlySalary <= 0) {
            return [
                'years_of_service' => 0,
                'severance_months' => 0,
                'reward_months' => 0,
                'severance_amount' => 0.0,
                'reward_amount' => 0.0,
                'compensation_amount' => 0.0,
                'total_package' => 0.0,
            ];
        }

        $years = $hire->diffInYears($term);

        // 1. Pesangon (UP) Base Months
        $upMonths = match (true) {
            $years < 1 => 1,
            $years < 2 => 2,
            $years < 3 => 3,
            $years < 4 => 4,
            $years < 5 => 5,
            $years < 6 => 6,
            $years < 7 => 7,
            $years < 8 => 8,
            default => 9,
        };

        // 2. Penghargaan Masa Kerja (UPMK) Base Months
        $upmkMonths = match (true) {
            $years < 3 => 0,
            $years < 6 => 2,
            $years < 9 => 3,
            $years < 12 => 4,
            $years < 15 => 5,
            $years < 18 => 6,
            $years < 21 => 7,
            $years < 24 => 8,
            default => 10,
        };

        // Multiplier factor based on termination reason
        $multiplier = match ($reason) {
            'resignation' => 0.0, // Resignation receives UPH only (or policy-defined Pisah)
            'end_of_contract' => 0.0, // PKWT compensation handled separately
            'redundancy', 'efficiency' => 1.0,
            'retirement', 'pensiun' => 1.75,
            'death' => 2.0,
            default => 1.0,
        };

        $finalUpMonths = $upMonths * $multiplier;
        $finalUpmkMonths = in_array($reason, ['resignation', 'end_of_contract'], true) ? 0 : $upmkMonths;

        $upAmount = round($monthlySalary * $finalUpMonths, 2);
        $upmkAmount = round($monthlySalary * $finalUpmkMonths, 2);
        $uphAmount = round(($upAmount + $upmkAmount) * 0.15, 2); // 15% standard UPH

        return [
            'years_of_service' => $years,
            'severance_months' => $finalUpMonths,
            'reward_months' => $finalUpmkMonths,
            'severance_amount' => $upAmount,
            'reward_amount' => $upmkAmount,
            'compensation_amount' => $uphAmount,
            'total_package' => round($upAmount + $upmkAmount + $uphAmount, 2),
        ];
    }
}
