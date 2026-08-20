<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PartnerMergeLog extends Model
{
    protected $table = 'CRM.partner_merge_log';

    public $timestamps = false;

    protected $fillable = ['merged_from_partner_id', 'merged_into_partner_id', 'merged_by', 'merged_at', 'field_conflicts'];

    protected $casts = [
        'merged_at' => 'datetime',
        'field_conflicts' => 'array',
    ];

    public function mergedFrom()
    {
        return $this->belongsTo(Partner::class, 'merged_from_partner_id');
    }

    public function mergedInto()
    {
        return $this->belongsTo(Partner::class, 'merged_into_partner_id');
    }

    public function mergedBy()
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
