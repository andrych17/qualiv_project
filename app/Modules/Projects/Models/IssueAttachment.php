<?php

namespace App\Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IssueAttachment extends Model
{
    protected $table = 'PROJECTS.issue_attachments';

    protected $fillable = [
        'uuid',
        'issue_id',
        'user_id',
        'original_name',
        'storage_key',
        'mime_type',
        'size',
    ];

    protected static function booted(): void
    {
        static::creating(function (IssueAttachment $attachment) {
            if (empty($attachment->uuid)) {
                $attachment->uuid = (string) Str::uuid();
            }
        });
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True when the stored file is renderable inline in the browser (images, text-ish). */
    public function isPreviewable(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
