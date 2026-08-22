<?php

namespace App\Modules\DMS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Folder extends Model
{
    protected $table = 'DMS.folders';

    public const ACCESS_PRIVATE = 'private';

    public const ACCESS_TEAM = 'team';

    public const ACCESS_TENANT = 'tenant';

    public const ACCESS_FLAGS = [self::ACCESS_PRIVATE, self::ACCESS_TEAM, self::ACCESS_TENANT];

    protected $fillable = [
        'parent_folder_id', 'name', 'default_doc_type_id', 'default_retention_policy_id',
        'access_flag', 'created_by',
    ];

    /**
     * §3A: folder access is enforced at query time, not just UI-hidden. `team` currently
     * collapses to tenant-wide (no team/membership model exists yet, see migration note) —
     * only `private` actually narrows the result set, to the folder's own creator.
     */
    public function scopeAccessibleTo(Builder $query, int $userId): void
    {
        $query->where(fn ($q) => $q->where('access_flag', '!=', self::ACCESS_PRIVATE)->orWhere('created_by', $userId));
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_folder_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_folder_id');
    }

    public function defaultDocType()
    {
        return $this->belongsTo(DocType::class, 'default_doc_type_id');
    }

    public function defaultRetentionPolicy()
    {
        return $this->belongsTo(RetentionPolicy::class, 'default_retention_policy_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
