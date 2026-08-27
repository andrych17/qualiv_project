<?php

namespace App\Modules\Purchase\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class PurRfxLine extends Model
{
    protected $table = 'PURCHASE.pur_rfx_lines';

    public $timestamps = false;

    protected $fillable = [
        'rfx_id',
        'line_no',
        'description',
        'qty',
        'awarded_supplier_id',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'qty' => 'decimal:4',
    ];

    public function rfx()
    {
        return $this->belongsTo(PurRfxHdr::class, 'rfx_id');
    }

    public function awardedSupplier()
    {
        return $this->belongsTo(Partner::class, 'awarded_supplier_id');
    }

    public function responseLines()
    {
        return $this->hasMany(PurRfxResponseLine::class, 'rfx_line_id');
    }
}
