<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurRfxResponseLine extends Model
{
    protected $table = 'PURCHASE.pur_rfx_response_lines';

    public $timestamps = false;

    protected $fillable = [
        'response_id',
        'rfx_line_id',
        'price',
        'lead_time_days',
        'notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'lead_time_days' => 'integer',
    ];

    public function response()
    {
        return $this->belongsTo(PurRfxResponse::class, 'response_id');
    }

    public function rfxLine()
    {
        return $this->belongsTo(PurRfxLine::class, 'rfx_line_id');
    }
}
