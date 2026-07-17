<?php

namespace App\Modules\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FieldDef extends Model
{
    protected $table = 'CUSTOMFIELDS.field_defs';

    protected $fillable = [
        'uuid',
        'entity_type',
        'code',
        'label',
        'field_type',
        'options',
        'is_required',
        'seq',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FieldDef $def) {
            if (empty($def->uuid)) {
                $def->uuid = (string) Str::uuid();
            }
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(FieldValue::class, 'field_def_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType)->orderBy('seq')->orderBy('id');
    }
}
