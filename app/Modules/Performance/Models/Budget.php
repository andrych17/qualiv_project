<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3B — a budget header for a subject (company/org_unit/employee) and fiscal year(+quarter). */
class Budget extends Model
{
    protected $table = 'PERF.budget_hdrs';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_LOCKED = 'locked';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_LOCKED];

    protected $fillable = [
        'name', 'subject_type', 'subject_id', 'fiscal_year', 'fiscal_quarter',
        'status', 'owner_id', 'version_no', 'prior_version_id', 'notes', 'created_by',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['fiscal_year'] ?? null, function ($query, $year) {
            $query->where('fiscal_year', $year);
        })->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        });
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function priorVersion()
    {
        return $this->belongsTo(self::class, 'prior_version_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
