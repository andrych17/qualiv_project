<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3B — one category/period slice of a Budget, e.g. "Marketing" for 2026-08. */
class BudgetLine extends Model
{
    protected $table = 'PERF.budget_lines';

    protected $fillable = ['budget_id', 'category', 'period_id', 'amount_planned', 'notes'];

    protected $casts = [
        'amount_planned' => 'decimal:4',
    ];

    public function budget()
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function actual()
    {
        return $this->hasOne(BudgetActual::class);
    }
}
