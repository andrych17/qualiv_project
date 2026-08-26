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

    public const DEFAULT_KES_EMPLOYER_RATE = 0.0400;

    public const DEFAULT_KES_EMPLOYEE_RATE = 0.0100;

    public const DEFAULT_JHT_EMPLOYER_RATE = 0.0370;

    public const DEFAULT_JHT_EMPLOYEE_RATE = 0.0200;

    public const DEFAULT_JP_EMPLOYER_RATE = 0.0200;

    public const DEFAULT_JP_EMPLOYEE_RATE = 0.0100;

    public const DEFAULT_JKM_EMPLOYER_RATE = 0.0030;

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

        $kesConfig = $this->getConfig(BpjsConfig::PROG_KES, self::DEFAULT_KES_EMPLOYER_RATE, self::DEFAULT_KES_EMPLOYEE_RATE, self::DEFAULT_KES_CAP);
        $jhtConfig = $this->getConfig(BpjsConfig::PROG_JHT, self::DEFAULT_JHT_EMPLOYER_RATE, self::DEFAULT_JHT_EMPLOYEE_RATE);
        $jpConfig = $this->getConfig(BpjsConfig::PROG_JP, self::DEFAULT_JP_EMPLOYER_RATE, self::DEFAULT_JP_EMPLOYEE_RATE, self::DEFAULT_JP_CAP);
        $jkmConfig = $this->getConfig(BpjsConfig::PROG_JKM, self::DEFAULT_JKM_EMPLOYER_RATE, 0.0);

        // 1. BPJS Kesehatan
        $kesBasis = $kesConfig['wage_cap'] ? min($basicSalary, $kesConfig['wage_cap']) : $basicSalary;
        $kesEmployer = round($kesBasis * $kesConfig['employer_rate'], 2);
        $kesEmployee = round($kesBasis * $kesConfig['employee_rate'], 2);

        // 2. BPJS TK - JHT
        $jhtEmployer = round($basicSalary * $jhtConfig['employer_rate'], 2);
        $jhtEmployee = round($basicSalary * $jhtConfig['employee_rate'], 2);

        // 3. BPJS TK - JP
        $jpBasis = $jpConfig['wage_cap'] ? min($basicSalary, $jpConfig['wage_cap']) : $basicSalary;
        $jpEmployer = round($jpBasis * $jpConfig['employer_rate'], 2);
        $jpEmployee = round($jpBasis * $jpConfig['employee_rate'], 2);

        // 4. BPJS TK - JKK
        $jkkEmployer = round($basicSalary * $jkkRiskRate, 2);

        // 5. BPJS TK - JKM
        $jkmEmployer = round($basicSalary * $jkmConfig['employer_rate'], 2);

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

    /**
     * @return array{employer_rate: float, employee_rate: float, wage_cap: ?float}
     */
    protected function getConfig(string $progCode, float $defaultEmployerRate, float $defaultEmployeeRate, ?float $defaultCap = null): array
    {
        try {
            $config = BpjsConfig::query()->where('program_code', $progCode)->first();
            if ($config) {
                return [
                    'employer_rate' => $config->employer_rate !== null ? (float) $config->employer_rate : $defaultEmployerRate,
                    'employee_rate' => $config->employee_rate !== null ? (float) $config->employee_rate : $defaultEmployeeRate,
                    'wage_cap' => $config->wage_cap !== null ? (float) $config->wage_cap : $defaultCap,
                ];
            }
        } catch (\Throwable) {
            // Fallback gracefully
        }

        return [
            'employer_rate' => $defaultEmployerRate,
            'employee_rate' => $defaultEmployeeRate,
            'wage_cap' => $defaultCap,
        ];
    }
}
