<?php

namespace App\Modules\DMS\Models;

use Illuminate\Database\Eloquent\Model;

class DocType extends Model
{
    protected $table = 'DMS.doc_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
