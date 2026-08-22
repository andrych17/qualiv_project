<?php

namespace App\Modules\DMS\Models;

use Illuminate\Database\Eloquent\Model;

class RetentionPolicy extends Model
{
    protected $table = 'DMS.retention_policies';

    public $timestamps = false;

    public const ACTION_NOTIFY_ONLY = 'notify_only';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_DELETE = 'delete';

    protected $fillable = [
        'doc_type_id', 'retention_period_days', 'action_on_expiry', 'legal_hold_overridable', 'is_active',
    ];

    protected $casts = [
        'legal_hold_overridable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function docType()
    {
        return $this->belongsTo(DocType::class);
    }
}
