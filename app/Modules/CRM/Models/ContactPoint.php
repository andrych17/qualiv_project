<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class ContactPoint extends Model
{
    protected $table = 'CRM.contact_points';

    public $timestamps = false;

    protected $fillable = ['partner_id', 'type', 'value', 'is_primary', 'opt_out'];

    protected $casts = [
        'is_primary' => 'boolean',
        'opt_out' => 'boolean',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
