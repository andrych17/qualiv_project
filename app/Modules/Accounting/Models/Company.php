<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3K Multi Company (minimal master, §3B's own dependency) — full switcher/combined reporting is a later build. */
class Company extends Model
{
    protected $table = 'ACCOUNTING.companies';

    protected $fillable = [
        'legal_name', 'npwp', 'address', 'base_currency', 'fiscal_year_start_month', 'coa_template_code',
        'ar_control_account_id', 'ap_control_account_id', 'is_active',
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

    public function fiscalYears()
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function costCenters()
    {
        return $this->hasMany(CostCenter::class);
    }
}
