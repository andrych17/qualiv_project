<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PurOrderRevision extends Model
{
    public $timestamps = false;

    protected $table = 'PURCHASE.pur_order_revisions';

    protected $fillable = [
        'po_id',
        'revision_no',
        'snapshot',
        'revised_by',
        'revised_at',
    ];

    protected $casts = [
        'revision_no' => 'integer',
        'snapshot' => 'array',
        'revised_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(PurOrderHdr::class, 'po_id');
    }

    public function revisedBy()
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
