<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollComponent extends Model
{
    protected $table = 'PAYROLL.payroll_components';

    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const CATEGORY_FIXED = 'fixed';

    public const CATEGORY_FORMULA = 'formula';

    public const CATEGORY_STATUTORY = 'statutory';

    public const CATEGORY_VARIABLE_INPUT = 'variable_input';

    protected $fillable = [
        'code',
        'name',
        'type',
        'category',
        'calculation_basis',
        'is_taxable',
        'is_bpjs_basis',
        'gl_account_code',
        'is_system_defined',
        'is_active',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_bpjs_basis' => 'boolean',
        'is_system_defined' => 'boolean',
        'is_active' => 'boolean',
    ];
}
