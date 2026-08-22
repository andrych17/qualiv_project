<?php

namespace App\Modules\DMS\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentRelation extends Model
{
    protected $table = 'DMS.document_relations';

    public $timestamps = false;

    public const TYPE_AMENDMENT_OF = 'amendment_of';

    public const TYPE_SUPERSEDES = 'supersedes';

    public const TYPE_ATTACHMENT_OF = 'attachment_of';

    public const TYPE_RELATED_TO = 'related_to';

    protected $fillable = ['source_document_id', 'target_document_id', 'relation_type', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function source()
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function target()
    {
        return $this->belongsTo(Document::class, 'target_document_id');
    }
}
