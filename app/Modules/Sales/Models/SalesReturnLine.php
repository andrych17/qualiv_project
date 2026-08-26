<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    protected $table = 'SALES.ret_lines';

    protected $fillable = [
        'ret_hdr_id',
        'so_line_id',
        'qty_returned',
        'condition_notes',
    ];

    protected $casts = [
        'qty_returned' => 'decimal:3',
    ];

    public function return()
    {
        return $this->belongsTo(SalesReturn::class, 'ret_hdr_id');
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class, 'so_line_id');
    }
}
