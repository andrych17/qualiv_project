<?php

namespace App\Modules\DMS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * §3I Audit Trail — append-only, no update/delete permitted at the app layer. Enforced here,
 * not just by convention: `updating` blocks any save() on an existing row, delete() always
 * throws. This is an app-layer (Eloquent) guard, matching the spec's own "at the app layer"
 * phrasing — not a DB-level REVOKE, since CreateModuleSchemas grants ALL PRIVILEGES schema-wide
 * and re-running it would silently undo a DB-level restriction anyway.
 */
class AccessLog extends Model
{
    protected $table = 'DMS.access_logs';

    public $timestamps = false;

    public const ACTION_UPLOAD = 'upload';

    public const ACTION_VIEW = 'view';

    public const ACTION_DOWNLOAD = 'download';

    public const ACTION_EDIT_METADATA = 'edit_metadata';

    public const ACTION_VERSION_UPLOAD = 'version_upload';

    public const ACTION_RESTORE = 'restore';

    public const ACTION_DELETE = 'delete';

    public const ACTION_PERMISSION_CHANGE = 'permission_change';

    public const ACTION_HOLD_APPLIED = 'hold_applied';

    public const ACTION_HOLD_RELEASED = 'hold_released';

    /**
     * §3F: not in DMS_SPECS.md's original access_logs action list, but the spec explicitly
     * requires logging this exact event ("If legal_hold = true ... log a 'hold prevented
     * action' audit entry") — extending the app-level enum is safe since this column has no DB
     * CHECK constraint (WNE's migration convention, see DMS's own create-tables migration).
     */
    public const ACTION_HOLD_BLOCKED = 'hold_blocked';

    /**
     * §3F retention sweep transitions — one literal action per outcome (not a single generic
     * bucket) so the audit log stays directly filterable, same convention every other action
     * here already follows (upload vs version_upload vs restore are separate literals too).
     */
    public const ACTION_EXPIRED = 'expired';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_DELETE_REQUESTED = 'delete_requested';

    /** @var list<string> every action this module ever logs — §3I's audit page filter dropdown. */
    public const ACTIONS = [
        self::ACTION_UPLOAD, self::ACTION_VIEW, self::ACTION_DOWNLOAD, self::ACTION_EDIT_METADATA,
        self::ACTION_VERSION_UPLOAD, self::ACTION_RESTORE, self::ACTION_DELETE, self::ACTION_PERMISSION_CHANGE,
        self::ACTION_HOLD_APPLIED, self::ACTION_HOLD_RELEASED, self::ACTION_HOLD_BLOCKED,
        self::ACTION_EXPIRED, self::ACTION_ARCHIVED, self::ACTION_DELETE_REQUESTED,
    ];

    protected $fillable = [
        'document_id', 'document_version_id', 'action', 'actor_id', 'ip_address', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('DMS.access_logs is append-only — rows cannot be updated at the app layer.');
        });
    }

    public function delete()
    {
        throw new LogicException('DMS.access_logs is append-only — rows cannot be deleted at the app layer.');
    }

    /**
     * Every AccessLog::create() call in this module should go through here instead — fills
     * ip_address from the current request automatically (never in a console context, e.g. the
     * §3F retention sweep, where there is no request to read one from — that's the "optional"
     * half of DMS_SPECS.md §3I's "actor, timestamp, IP (optional)").
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        return static::query()->create([
            ...$attributes,
            'ip_address' => $attributes['ip_address'] ?? (app()->runningInConsole() ? null : request()?->ip()),
        ]);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(fn ($q) => $q
                ->whereHas('document', fn ($q2) => $q2->where('title', 'ilike', "%{$search}%"))
                ->orWhereHas('actor', fn ($q2) => $q2->where('name', 'ilike', "%{$search}%")));
        })->when($filters['action'] ?? null, function ($query, $action) {
            $query->where('action', $action);
        })->when($filters['document_id'] ?? null, function ($query, $documentId) {
            $query->where('document_id', $documentId);
        })->when($filters['actor_id'] ?? null, function ($query, $actorId) {
            $query->where('actor_id', $actorId);
        });
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
