<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3L lookup only — seeded (IDR/USD), no management UI in this pass. */
class Currency extends Model
{
    protected $table = 'ACCOUNTING.currencies';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_enabled'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
