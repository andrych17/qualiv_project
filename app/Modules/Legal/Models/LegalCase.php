<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LegalCase extends Model
{
    protected $table = 'LEGAL.cases';

    protected $fillable = [
        'uuid',
        'code',
        'title',
        'status',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegalCase $case) {
            if (empty($case->uuid)) {
                $case->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('title', 'ilike', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }
}
