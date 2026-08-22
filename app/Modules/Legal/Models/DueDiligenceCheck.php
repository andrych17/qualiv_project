<?php

namespace App\Modules\Legal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DueDiligenceCheck extends Model
{
    protected $table = 'LEGAL.due_diligence_checks';

    public const TYPE_SERTIFIKAT_VALIDITY = 'sertifikat_validity';

    public const TYPE_PBB_PAYMENT_STATUS = 'pbb_payment_status';

    public const TYPE_BLOKIR_SENGKETA = 'blokir_sengketa';

    public const TYPE_ZONA_NILAI_TANAH = 'zona_nilai_tanah';

    public const TYPES = [
        self::TYPE_SERTIFIKAT_VALIDITY, self::TYPE_PBB_PAYMENT_STATUS,
        self::TYPE_BLOKIR_SENGKETA, self::TYPE_ZONA_NILAI_TANAH,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEAR = 'clear';

    public const STATUS_FLAGGED = 'flagged';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CLEAR, self::STATUS_FLAGGED];

    protected $fillable = [
        'land_object_id', 'check_type', 'status', 'checked_by', 'checked_at', 'result_notes',
        'overridden_by', 'overridden_at', 'override_justification',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'overridden_at' => 'datetime',
    ];

    /** §3G's future signing gate reads this — flagged and not (yet) overridden blocks. */
    public function isBlocking(): bool
    {
        return $this->status === self::STATUS_FLAGGED && $this->overridden_at === null;
    }

    public function landObject()
    {
        return $this->belongsTo(LandObject::class);
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function overriddenByUser()
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
