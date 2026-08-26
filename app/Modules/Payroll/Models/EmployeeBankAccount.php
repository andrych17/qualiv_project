<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBankAccount extends Model
{
    protected $table = 'PAYROLL.employee_bank_accounts';

    protected $fillable = [
        'employee_id',
        'bank_master_id',
        'bank_name',
        'account_number',
        'account_holder_name',
        'is_primary',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function bankMaster(): BelongsTo
    {
        return $this->belongsTo(BankMaster::class, 'bank_master_id');
    }
}
