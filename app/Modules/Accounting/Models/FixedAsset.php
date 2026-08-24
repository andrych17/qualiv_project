<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3G — one asset register row. Depreciation starts the month of acquisition_date (full-month convention, both schedules). */
class FixedAsset extends Model
{
    protected $table = 'ACCOUNTING.fa_assets';

    public const METHOD_STRAIGHT_LINE = 'straight_line';

    public const METHOD_DECLINING_BALANCE = 'declining_balance';

    public const METHODS = [self::METHOD_STRAIGHT_LINE, self::METHOD_DECLINING_BALANCE];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'uuid', 'company_id', 'asset_group_id', 'asset_no', 'name', 'vendor_partner_id',
        'acquisition_date', 'acquisition_cost',
        'asset_gl_account_id', 'accumulated_depreciation_gl_account_id', 'depreciation_expense_gl_account_id',
        'commercial_useful_life_months', 'commercial_method', 'commercial_declining_rate', 'fiscal_method',
        'subject_type', 'subject_id', 'status', 'created_by',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'acquisition_cost' => 'decimal:2',
        'commercial_declining_rate' => 'decimal:4',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assetGroup()
    {
        return $this->belongsTo(AssetGroup::class, 'asset_group_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Partner::class, 'vendor_partner_id');
    }

    public function assetGlAccount()
    {
        return $this->belongsTo(Account::class, 'asset_gl_account_id');
    }

    public function accumulatedDepreciationGlAccount()
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_gl_account_id');
    }

    public function depreciationExpenseGlAccount()
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_gl_account_id');
    }

    public function commercialSchedule()
    {
        return $this->hasMany(DepreciationScheduleCommercial::class, 'asset_id')->orderBy('fiscal_period_id');
    }

    public function fiscalSchedule()
    {
        return $this->hasMany(DepreciationScheduleFiscal::class, 'asset_id')->orderBy('fiscal_period_id');
    }

    public function disposal()
    {
        return $this->hasOne(AssetDisposal::class, 'asset_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
