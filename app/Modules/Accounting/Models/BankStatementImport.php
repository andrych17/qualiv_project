<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — one CSV upload, staged for future reconciliation (§3Q, not built). */
class BankStatementImport extends Model
{
    protected $table = 'ACCOUNTING.bank_statement_imports';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'bank_account_id', 'object_key', 'original_filename',
        'line_count', 'imported_by', 'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class, 'import_id');
    }
}
