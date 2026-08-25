<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsIssueLine extends Model
{
    protected $table = 'INVENTORY.goods_issue_lines';

    public $timestamps = false;

    protected $fillable = ['goods_issue_id', 'product_id', 'batch_id', 'qty', 'uom_id', 'source_location_id', 'expiry_override_reason', 'serial_numbers'];

    protected $casts = [
        'qty' => 'decimal:4',
        'serial_numbers' => 'array',
    ];

    public function issue()
    {
        return $this->belongsTo(GoodsIssue::class, 'goods_issue_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function sourceLocation()
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
