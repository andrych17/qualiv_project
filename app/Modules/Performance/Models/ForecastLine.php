<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3H — one period slice's projected value within a Forecast version. */
class ForecastLine extends Model
{
    protected $table = 'PERF.forecast_lines';

    protected $fillable = ['forecast_id', 'period_id', 'forecast_value'];

    protected $casts = [
        'forecast_value' => 'decimal:4',
    ];

    public function forecast()
    {
        return $this->belongsTo(Forecast::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }
}
