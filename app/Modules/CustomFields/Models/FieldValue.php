<?php

namespace App\Modules\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldValue extends Model
{
    protected $table = 'CUSTOMFIELDS.field_values';

    protected $fillable = [
        'field_def_id',
        'entity_type',
        'entity_id',
        'value',
    ];

    public function def(): BelongsTo
    {
        return $this->belongsTo(FieldDef::class, 'field_def_id');
    }
}
