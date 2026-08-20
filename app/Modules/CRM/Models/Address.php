<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $table = 'CRM.addresses';

    public $timestamps = false;

    protected $fillable = [
        'partner_id', 'type', 'line1', 'line2', 'city',
        'state_province', 'postal_code', 'country', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
