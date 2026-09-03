<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3L — one inspection event, scoped to an order (assembly) or a batch phase (process). */
class QcSample extends Model
{
    protected $table = 'MES.mes_qc_samples';

    public $timestamps = false;

    protected $fillable = ['order_id', 'batch_phase_id', 'sample_number', 'taken_by', 'taken_at'];

    protected $casts = [
        'taken_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function batchPhase()
    {
        return $this->belongsTo(BatchPhase::class, 'batch_phase_id');
    }

    public function takenBy()
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function results()
    {
        return $this->hasMany(QcResult::class, 'sample_id');
    }
}
