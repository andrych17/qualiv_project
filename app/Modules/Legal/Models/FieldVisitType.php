<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;

class FieldVisitType extends Model
{
    protected $table = 'LEGAL.field_visit_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'default_checklist', 'is_active'];

    protected $casts = [
        'default_checklist' => 'array',
        'is_active' => 'boolean',
    ];
}
