<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;

class DeedType extends Model
{
    protected $table = 'LEGAL.deed_types';

    public $timestamps = false;

    public const CATEGORY_NOTARY = 'notary';

    public const CATEGORY_PPAT = 'ppat';

    protected $fillable = [
        'code', 'name', 'category', 'requires_tax',
        'requires_bpn_registration', 'default_protocol_book_type', 'is_active',
    ];

    protected $casts = [
        'requires_tax' => 'boolean',
        'requires_bpn_registration' => 'boolean',
        'is_active' => 'boolean',
    ];
}
