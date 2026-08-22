<?php

namespace App\Modules\Legal\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class Will extends Model
{
    protected $table = 'LEGAL.wills';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_DPW_REGISTERED = 'dpw_registered';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_OPENED = 'opened';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_DRAFTED, self::STATUS_DPW_REGISTERED, self::STATUS_ACTIVE,
        self::STATUS_OPENED, self::STATUS_REVOKED,
    ];

    protected $fillable = [
        'deed_id', 'testator_partner_id', 'dpw_reg_number', 'dpw_registered_at', 'status', 'closing_notes',
    ];

    protected $casts = [
        'dpw_registered_at' => 'date',
    ];

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }

    public function testator()
    {
        return $this->belongsTo(Partner::class, 'testator_partner_id');
    }

    /** §3D — the single highest-liability gap: signed but not yet registered past the grace window. */
    public function isOverdueForDpw(int $graceDays): bool
    {
        if ($this->status !== self::STATUS_DRAFTED || $this->deed->status !== Deed::STATUS_SIGNED) {
            return false;
        }

        return $this->deed->signing_date !== null
            && $this->deed->signing_date->addDays($graceDays)->isPast();
    }
}
