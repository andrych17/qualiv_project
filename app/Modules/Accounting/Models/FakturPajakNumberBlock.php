<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3M — a tenant-entered DJP number-allocation block that FakturPajakService::issueOutput() draws from sequentially. */
class FakturPajakNumberBlock extends Model
{
    protected $table = 'ACCOUNTING.faktur_pajak_number_blocks';

    protected $fillable = ['company_id', 'prefix', 'range_start', 'range_end', 'last_issued', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function remaining(): int
    {
        return $this->range_end - ($this->last_issued ?? $this->range_start - 1);
    }
}
