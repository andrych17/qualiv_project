<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3B — manual actual entry for one BudgetLine, used when the line's category isn't GL-mapped. */
class BudgetActual extends Model
{
    protected $table = 'PERF.budget_actuals';

    public $timestamps = false;

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['budget_line_id', 'actual_value', 'source', 'entered_by', 'entered_at'];

    protected $casts = [
        'actual_value' => 'decimal:4',
        'entered_at' => 'datetime',
    ];

    public function budgetLine()
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
