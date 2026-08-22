<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;

class BpnSubmission extends Model
{
    protected $table = 'LEGAL.bpn_submissions';

    public const TYPE_BALIK_NAMA = 'balik_nama';

    public const TYPE_APHT_REGISTRATION = 'apht_registration';

    public const TYPE_SPLIT = 'split';

    public const TYPE_MERGE = 'merge';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_BALIK_NAMA, self::TYPE_APHT_REGISTRATION, self::TYPE_SPLIT, self::TYPE_MERGE, self::TYPE_OTHER];

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_IN_PROCESS = 'in_process';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'deed_id', 'submission_type', 'submitted_at', 'tracking_number', 'pnbp_amount',
        'status', 'completed_at', 'rejection_reason', 'resubmission_of_id',
    ];

    protected $casts = [
        'submitted_at' => 'date',
        'completed_at' => 'date',
        'pnbp_amount' => 'decimal:2',
    ];

    /** Current BPN PNBP convention (editable — local variations exist), §3L. */
    public static function calculatePnbp(float $transactionValue): float
    {
        return round($transactionValue / 1000 + 50000, 2);
    }

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }

    public function resubmissionOf()
    {
        return $this->belongsTo(self::class, 'resubmission_of_id');
    }
}
