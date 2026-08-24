<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3S — maps a Payroll component (by string code — no live table to reference, see the
 * migration docblock) to the GL account(s) it posts to. `gl_account_id` is the
 * expense (earning/employer_cost) or payable (deduction) side; `payable_account_id` is
 * ADDITIONALLY required for employer_cost rows (see PayrollGlPostingService for the
 * balancing arithmetic this two-column split makes work).
 */
class PayrollComponentGlMapping extends Model
{
    protected $table = 'ACCOUNTING.payroll_component_gl_mappings';

    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_EMPLOYER_COST = 'employer_cost';

    public const TYPES = [self::TYPE_EARNING, self::TYPE_DEDUCTION, self::TYPE_EMPLOYER_COST];

    protected $fillable = [
        'uuid', 'company_id', 'component_code', 'component_label', 'component_type',
        'gl_account_id', 'payable_account_id', 'created_by',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount()
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function payableAccount()
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }
}
