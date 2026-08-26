<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class BpjsConfig extends Model
{
    protected $table = 'PAYROLL.bpjs_config';

    public const PROG_KES = 'KES';

    public const PROG_JHT = 'JHT';

    public const PROG_JP = 'JP';

    public const PROG_JKK = 'JKK';

    public const PROG_JKM = 'JKM';

    protected $fillable = [
        'program_code',
        'name',
        'employer_rate',
        'employee_rate',
        'wage_cap',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'employer_rate' => 'decimal:4',
        'employee_rate' => 'decimal:4',
        'wage_cap' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];
}
