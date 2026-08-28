<?php

namespace App\Modules\Performance\Data;

/** §3G — actual-vs-plan comparison for one subject/metric/period. */
class VarianceResult
{
    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BREACH = 'breach';

    /** §3B — which source produced $actualValue. Null for the KPI path, where this duality doesn't exist. */
    public const SOURCE_GL = 'gl';

    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly float $planValue,
        public readonly float $actualValue,
        public readonly float $varianceAbs,
        public readonly ?float $variancePct,
        public readonly string $status,
        public readonly ?string $actualSource = null,
    ) {}
}
