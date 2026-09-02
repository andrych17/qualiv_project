<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3L — one characteristic's outcome within a sample. */
class QcResult extends Model
{
    protected $table = 'MES.mes_qc_results';

    public $timestamps = false;

    public const RESULT_PASS = 'pass';

    public const RESULT_FAIL = 'fail';

    public const RESULT_HOLD = 'hold';

    protected $fillable = ['sample_id', 'characteristic_id', 'actual_value', 'result'];

    protected $casts = [
        'actual_value' => 'decimal:4',
    ];

    public function sample()
    {
        return $this->belongsTo(QcSample::class, 'sample_id');
    }

    public function characteristic()
    {
        return $this->belongsTo(QcCharacteristic::class, 'characteristic_id');
    }
}
