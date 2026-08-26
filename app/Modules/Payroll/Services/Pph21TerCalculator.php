<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\Pph21TerRate;
use App\Modules\Payroll\Models\PtkpStatus;

/**
 * PPh 21 TER (Tarif Efektif Rata-rata) Engine per PP 58/2023 & PMK 168/2023.
 */
class Pph21TerCalculator
{
    /**
     * Calculate monthly PPh 21 using TER tables.
     *
     * @return array{
     *     ter_category: string,
     *     rate_percentage: float,
     *     tax_amount: float,
     *     is_non_npwp_penalized: bool
     * }
     */
    public function calculate(float $grossMonthly, string $ptkpCode = 'TK/0', bool $hasNpwp = true): array
    {
        if ($grossMonthly <= 0) {
            return [
                'ter_category' => 'A',
                'rate_percentage' => 0.0,
                'tax_amount' => 0.0,
                'is_non_npwp_penalized' => false,
            ];
        }

        $terCategory = $this->fallbackTerCategory($ptkpCode);
        $rate = $this->fallbackTerRate($terCategory, $grossMonthly);

        try {
            // 1. Resolve TER Category (A, B, or C) from PTKP status
            $ptkp = PtkpStatus::query()->where('code', $ptkpCode)->first();
            if ($ptkp && $ptkp->ter_category) {
                $terCategory = $ptkp->ter_category;
            }

            // 2. Lookup rate percentage from brackets
            $rateRow = Pph21TerRate::query()
                ->where('ter_category', $terCategory)
                ->where('min_gross_monthly', '<=', $grossMonthly)
                ->where(function ($q) use ($grossMonthly) {
                    $q->whereNull('max_gross_monthly')
                        ->orWhere('max_gross_monthly', '>=', $grossMonthly);
                })
                ->orderBy('rate_percentage', 'desc')
                ->first();

            if ($rateRow) {
                $rate = (float) $rateRow->rate_percentage;
            }
        } catch (\Throwable) {
            // Fallback gracefully to statutory PP 58/2023 defaults
        }

        $tax = $grossMonthly * $rate;

        // 3. 20% Non-NPWP Surcharge penalty
        $penalized = false;
        if (! $hasNpwp && $tax > 0) {
            $tax *= 1.20;
            $penalized = true;
        }

        return [
            'ter_category' => $terCategory,
            'rate_percentage' => $rate,
            'tax_amount' => round($tax, 2),
            'is_non_npwp_penalized' => $penalized,
        ];
    }

    protected function fallbackTerCategory(string $ptkpCode): string
    {
        return match ($ptkpCode) {
            'TK/0', 'TK/1', 'K/0' => 'A',
            'TK/2', 'TK/3', 'K/1', 'K/2' => 'B',
            'K/3' => 'C',
            default => 'A',
        };
    }

    protected function fallbackTerRate(string $category, float $gross): float
    {
        // Indonesian statutory baseline fallback brackets (PP 58/2023)
        if ($category === 'A') {
            if ($gross <= 5400000) {
                return 0.00;
            }
            if ($gross <= 5650000) {
                return 0.0025;
            }
            if ($gross <= 5950000) {
                return 0.005;
            }
            if ($gross <= 6300000) {
                return 0.0075;
            }
            if ($gross <= 6750000) {
                return 0.01;
            }
            if ($gross <= 7500000) {
                return 0.0125;
            }
            if ($gross <= 8550000) {
                return 0.015;
            }
            if ($gross <= 9650000) {
                return 0.0175;
            }
            if ($gross <= 10050000) {
                return 0.02;
            }
            if ($gross <= 10350000) {
                return 0.0225;
            }
            if ($gross <= 10700000) {
                return 0.025;
            }
            if ($gross <= 11050000) {
                return 0.03;
            }
            if ($gross <= 11600000) {
                return 0.035;
            }
            if ($gross <= 12500000) {
                return 0.04;
            }
            if ($gross <= 13750000) {
                return 0.05;
            }
            if ($gross <= 15100000) {
                return 0.06;
            }
            if ($gross <= 16950000) {
                return 0.07;
            }
            if ($gross <= 19750000) {
                return 0.08;
            }
            if ($gross <= 24150000) {
                return 0.09;
            }
            if ($gross <= 26450000) {
                return 0.10;
            }

            return 0.15;
        }

        if ($category === 'B') {
            if ($gross <= 6200000) {
                return 0.00;
            }
            if ($gross <= 6500000) {
                return 0.0025;
            }
            if ($gross <= 6850000) {
                return 0.005;
            }
            if ($gross <= 7300000) {
                return 0.0075;
            }
            if ($gross <= 9200000) {
                return 0.01;
            }
            if ($gross <= 10750000) {
                return 0.015;
            }
            if ($gross <= 11250000) {
                return 0.02;
            }
            if ($gross <= 11600000) {
                return 0.025;
            }
            if ($gross <= 12600000) {
                return 0.03;
            }
            if ($gross <= 13600000) {
                return 0.04;
            }
            if ($gross <= 14950000) {
                return 0.05;
            }

            return 0.09;
        }

        // Category C
        if ($gross <= 6600000) {
            return 0.00;
        }
        if ($gross <= 6950000) {
            return 0.0025;
        }
        if ($gross <= 7350000) {
            return 0.005;
        }
        if ($gross <= 7800000) {
            return 0.0075;
        }
        if ($gross <= 8850000) {
            return 0.01;
        }
        if ($gross <= 9800000) {
            return 0.0125;
        }
        if ($gross <= 10950000) {
            return 0.015;
        }
        if ($gross <= 11200000) {
            return 0.0175;
        }

        return 0.05;
    }
}
