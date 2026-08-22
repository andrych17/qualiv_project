<?php

namespace App\Modules\DMS\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'DMS.tags';

    public $timestamps = false;

    protected $fillable = ['name'];

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'DMS.document_tags');
    }
}
