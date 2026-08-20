<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Partner extends Model
{
    protected $table = 'CRM.partners';

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_ORGANIZATION = 'organization';

    protected $fillable = [
        'uuid', 'type', 'parent_partner_id', 'name', 'trade_name', 'title_position',
        'registration_tax_id', 'industry_id', 'tags', 'owner_id', 'source',
        'is_active', 'merged_into_partner_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Partner $partner) {
            if (empty($partner->uuid)) {
                $partner->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeContacts(Builder $query): void
    {
        $query->where('type', self::TYPE_INDIVIDUAL);
    }

    public function scopeCompanies(Builder $query): void
    {
        $query->where('type', self::TYPE_ORGANIZATION);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('trade_name', 'ilike', '%'.$search.'%')
                    ->orWhere('registration_tax_id', 'ilike', '%'.$search.'%');
            });
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_partner_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_partner_id');
    }

    public function industry()
    {
        return $this->belongsTo(Industry::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function contactPoints()
    {
        return $this->hasMany(ContactPoint::class);
    }

    public function roles()
    {
        return $this->hasMany(PartnerRole::class)->where('is_active', true);
    }
}
