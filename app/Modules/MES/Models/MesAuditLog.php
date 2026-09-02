<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3U — append-only field-level change log. No update/delete at the app layer, same discipline as `SYSCONFIG.config_audit_logs`. */
class MesAuditLog extends Model
{
    protected $table = 'MES.mes_audit_logs';

    public $timestamps = false;

    protected $fillable = ['subject_type', 'subject_id', 'action', 'actor_id', 'before_snapshot', 'after_snapshot', 'created_at'];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        })->when($filters['action'] ?? null, function ($query, $action) {
            $query->where('action', $action);
        })->when($filters['actor_id'] ?? null, function ($query, $actorId) {
            $query->where('actor_id', $actorId);
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
