<?php

namespace App\Modules\DMS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'DMS.documents';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_PURGED = 'purged';

    /** §3A "expiring soon" filter/rail window. */
    public const EXPIRING_SOON_DAYS = 30;

    /** §3B: CUSTOMFIELDS entity_type for this module's custom metadata (DMS_SPECS.sql). */
    public const CUSTOM_FIELD_ENTITY = 'dms_document';

    protected $fillable = [
        'uuid', 'folder_id', 'doc_type_id', 'title', 'description', 'subject_type', 'subject_id',
        'status', 'current_version_id', 'effective_date', 'expiry_date', 'retention_policy_id',
        'legal_hold', 'extracted_text',
    ];

    protected $casts = [
        'legal_hold' => 'boolean',
        'effective_date' => 'date',
        'expiry_date' => 'date',
    ];

    /**
     * §3E: matches the tsvector Postgres trigger maintains (title + current version's filename
     * + description + tags + extracted_text — see the search_vector migration). 'simple' config
     * on both sides (here and the trigger) — no stemming, language-agnostic for this tenant's
     * mixed Indonesian/English document titles.
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$search]);
        })->when($filters['folder_id'] ?? null, function ($query, $folderId) {
            $query->where('folder_id', $folderId);
        })->when($filters['doc_type_id'] ?? null, function ($query, $docTypeId) {
            $query->where('doc_type_id', $docTypeId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        })->when($filters['tag'] ?? null, function ($query, $tag) {
            $query->whereHas('tags', fn ($q) => $q->where('name', $tag));
        })->when($filters['flag'] ?? null, function ($query, $flag) {
            match ($flag) {
                'expiring_soon' => $query->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now(), now()->addDays(self::EXPIRING_SOON_DAYS)])
                    ->where('legal_hold', false),
                'on_legal_hold' => $query->where('legal_hold', true),
                default => null,
            };
        });
    }

    /** §3A Status Rail vocabulary: danger = expired/purged/on hold, warning = expiring soon, neutral = archived, success = active. */
    public function getRailAttribute(): string
    {
        if ($this->legal_hold || in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_PURGED], true)) {
            return 'danger';
        }
        if ($this->status === self::STATUS_ARCHIVED) {
            return 'neutral';
        }
        if ($this->expiry_date && now()->lessThanOrEqualTo($this->expiry_date) && now()->addDays(self::EXPIRING_SOON_DAYS)->greaterThanOrEqualTo($this->expiry_date)) {
            return 'warning';
        }

        return $this->status === self::STATUS_ACTIVE ? 'success' : 'neutral';
    }

    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }

    public function docType()
    {
        return $this->belongsTo(DocType::class);
    }

    public function currentVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function retentionPolicy()
    {
        return $this->belongsTo(RetentionPolicy::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'DMS.document_tags');
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class)->orderByDesc('created_at');
    }

    public function relationsFrom()
    {
        return $this->hasMany(DocumentRelation::class, 'source_document_id');
    }

    public function relationsTo()
    {
        return $this->hasMany(DocumentRelation::class, 'target_document_id');
    }
}
