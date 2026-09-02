<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3L — one measurable/pass-fail characteristic within an inspection plan. */
class QcCharacteristic extends Model
{
    protected $table = 'MES.mes_qc_characteristics';

    public $timestamps = false;

    public const SPEC_NUMERIC = 'numeric';

    public const SPEC_PASS_FAIL = 'pass_fail';

    protected $fillable = ['plan_id', 'characteristic_name', 'spec_type', 'target_value', 'min_value', 'max_value', 'uom_code'];

    protected $casts = [
        'target_value' => 'decimal:4',
        'min_value' => 'decimal:4',
        'max_value' => 'decimal:4',
    ];

    public function plan()
    {
        return $this->belongsTo(QcInspectionPlan::class, 'plan_id');
    }
}
