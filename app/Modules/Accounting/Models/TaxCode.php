<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3M PPN tax codes — rate/type/account mapping, configurable per company. */
class TaxCode extends Model
{
    protected $table = 'ACCOUNTING.tax_codes';

    public $timestamps = false;

    public const TYPE_OUTPUT = 'output';

    public const TYPE_INPUT = 'input';

    public const TYPES = [self::TYPE_OUTPUT, self::TYPE_INPUT];

    protected $fillable = ['company_id', 'code', 'rate', 'tax_type', 'gl_account_id', 'is_active'];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount()
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
