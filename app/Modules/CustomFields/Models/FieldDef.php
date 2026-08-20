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
        'module_code',
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

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('entity_type', 'ilike', '%'.$search.'%')
                        ->orWhere('code', 'ilike', '%'.$search.'%')
                        ->orWhere('label', 'ilike', '%'.$search.'%');
                });
            })
            ->when($filters['module_code'] ?? null, fn ($query, $code) => $query->where('module_code', $code))
            ->when($filters['entity_type'] ?? null, fn ($query, $type) => $query->where('entity_type', $type))
            ->when(($filters['show_inactive'] ?? null) !== '1', fn ($query) => $query->where('status', 'active'));
    }
}
