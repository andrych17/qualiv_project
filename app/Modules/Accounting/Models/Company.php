<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3K Multi Company (minimal master, §3B's own dependency) — full switcher/combined reporting is a later build. */
class Company extends Model
{
    protected $table = 'ACCOUNTING.companies';

    protected $fillable = [
        'legal_name', 'npwp', 'address', 'base_currency', 'fiscal_year_start_month', 'coa_template_code',
        'ar_control_account_id', 'ap_control_account_id', 'inventory_control_account_id', 'payroll_net_pay_payable_account_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /** §3D — the one AR control account §3D posting targets; set by AccountService::seedStarterCoa() or a company edit. */
    public function arControlAccount()
    {
        return $this->belongsTo(Account::class, 'ar_control_account_id');
    }

    /** §3E — the one AP control account §3E posting targets; set by AccountService::seedStarterCoa() or a company edit. */
    public function apControlAccount()
    {
        return $this->belongsTo(Account::class, 'ap_control_account_id');
    }

    /** §3H — the control account ControlReconciliationService::inventoryReport() checks the GL balance of; individual item/category mappings can point elsewhere, this is only the report's anchor. */
    public function inventoryControlAccount()
    {
        return $this->belongsTo(Account::class, 'inventory_control_account_id');
    }

    /** §3S — the one Net Pay Payable account every payroll run's balancing credit line posts to; per-component mappings (PayrollComponentGlMapping) are separate and cover the debit/deduction sides. */
    public function payrollNetPayPayableAccount()
    {
        return $this->belongsTo(Account::class, 'payroll_net_pay_payable_account_id');
    }

    public function fiscalYears()
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function costCenters()
    {
        return $this->hasMany(CostCenter::class);
    }
}
