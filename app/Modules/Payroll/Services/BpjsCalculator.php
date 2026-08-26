<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\BpjsConfig;

/**
 * BPJS Kesehatan and BPJS Ketenagakerjaan statutory calculation engine.
 */
class BpjsCalculator
{
    public const DEFAULT_KES_CAP = 12000000.0;
    public const DEFAULT_JP_CAP = 10042300.0;

    /**
     * Calculate all BPJS contributions for employee.
     *
     * @return array{
     *     kes_employer: float,
     *     kes_employee: float,
     *     jht_employer: float,
     *     jht_employee: float,
     *     jp_employer: float,
     *     jp_employee: float,
     *     jkk_employer: float,
     *     jkm_employer: float,
     *     total_employer: float,
     *     total_employee: float
     * }
     */
    public function calculate(float $basicSalary, float $jkkRiskRate = 0.0024): array
    {
        if ($basicSalary <= 0) {
            return [
                'kes_employer' => 0.0,
                'kes_employee' => 0.0,
                'jht_employer' => 0.0,
                'jht_employee' => 0.0,
                'jp_employer' => 0.0,
                'jp_employee' => 0.0,
                'jkk_employer' => 0.0,
                'jkm_employer' => 0.0,
                'total_employer' => 0.0,
                'total_employee' => 0.0,
            ];
        }

        // 1. BPJS Kesehatan (Cap Rp 12.000.000)
        $kesCap = $this->getWageCap(BpjsConfig::PROG_KES, self::DEFAULT_KES_CAP);
        $kesBasis = min($basicSalary, $kesCap);
        $kesEmployer = round($kesBasis * 0.0400, 2);
        $kesEmployee = round($kesBasis * 0.0100, 2);

        // 2. BPJS TK - JHT (Uncapped: 3.7% Employer, 2% Employee)
        $jhtEmployer = round($basicSalary * 0.0370, 2);
        $jhtEmployee = round($basicSalary * 0.0200, 2);

        // 3. BPJS TK - JP (Cap Rp 10.042.300: 2% Employer, 1% Employee)
        $jpCap = $this->getWageCap(BpjsConfig::PROG_JP, self::DEFAULT_JP_CAP);
        $jpBasis = min($basicSalary, $jpCap);
        $jpEmployer = round($jpBasis * 0.0200, 2);
        $jpEmployee = round($jpBasis * 0.0100, 2);

        // 4. BPJS TK - JKK (Employer only: risk rate 0.24% - 1.74%)
        $jkkEmployer = round($basicSalary * $jkkRiskRate, 2);

        // 5. BPJS TK - JKM (Employer only: 0.30%)
        $jkmEmployer = round($basicSalary * 0.0030, 2);

        $totalEmployer = round($kesEmployer + $jhtEmployer + $jpEmployer + $jkkEmployer + $jkmEmployer, 2);
        $totalEmployee = round($kesEmployee + $jhtEmployee + $jpEmployee, 2);

        return [
            'kes_employer' => $kesEmployer,
            'kes_employee' => $kesEmployee,
            'jht_employer' => $jhtEmployer,
            'jht_employee' => $jhtEmployee,
            'jp_employer' => $jpEmployer,
            'jp_employee' => $jpEmployee,
            'jkk_employer' => $jkkEmployer,
            'jkm_employer' => $jkmEmployer,
            'total_employer' => $totalEmployer,
            'total_employee' => $totalEmployee,
        ];
    }

    protected function getWageCap(string $progCode, float $defaultCap): float
    {
        try {
            $config = BpjsConfig::query()->where('program_code', $progCode)->first();
            if ($config && $config->wage_cap) {
                return (float) $config->wage_cap;
            }
        } catch (\Throwable) {
            // Fallback gracefully
        }

        return $defaultCap;
    }
}
