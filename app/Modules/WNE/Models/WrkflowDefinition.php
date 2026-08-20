<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class WrkflowDefinition extends Model
{
    protected $table = 'WNE.wrkflow_definitions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    protected $fillable = ['code', 'name', 'description', 'category_id', 'status', 'created_by'];

    public function category()
    {
        return $this->belongsTo(WrkflowCategory::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions()
    {
        return $this->hasMany(WrkflowVersion::class, 'definition_id');
    }

    /**
     * The version a start() call pins to (§3E). Guards on BOTH conditions:
     * status must be `published`, AND the row must have actually gone through
     * publish() (`published_by` set) — the highest version_no alone is not
     * enough once §3B lets editing create a newer draft row (published_by
     * NULL) on top of an already-published definition. Without the second
     * check, start() would silently run against a half-built draft.
     */
    public function currentPublishedVersion(): ?WrkflowVersion
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return null;
        }

        return $this->versions()->whereNotNull('published_by')->orderByDesc('version_no')->first();
    }

    /**
     * The version §3B's builder edits (add/remove steps & transitions on).
     * Definition of "draft": the one version row that has never been
     * published (`published_by` still NULL). At most one such row exists
     * per definition at a time — publish() always sets published_by, so the
     * next edit after a publish creates a fresh version_no+1 row to hold it.
     */
    public function draftVersion(): ?WrkflowVersion
    {
        return $this->versions()->whereNull('published_by')->orderByDesc('version_no')->first();
    }

    /** Last version that actually went through publish(), regardless of current status — the seed source when a new draft is opened after unpublish(). */
    public function lastPublishedVersion(): ?WrkflowVersion
    {
        return $this->versions()->whereNotNull('published_by')->orderByDesc('version_no')->first();
    }
}
