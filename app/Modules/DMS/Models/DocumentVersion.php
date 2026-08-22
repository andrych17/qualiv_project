<?php

namespace App\Modules\DMS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    protected $table = 'DMS.document_versions';

    public $timestamps = false;

    protected $fillable = [
        'document_id', 'version_no', 'original_filename', 'checksum_sha256', 'storage_key',
        'file_size_bytes', 'mime_type', 'version_note', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
