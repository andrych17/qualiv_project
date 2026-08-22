<?php

namespace App\Modules\Legal\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LandObject extends Model
{
    protected $table = 'LEGAL.land_objects';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_IN_TRANSACTION = 'in_transaction';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_IN_TRANSACTION, self::STATUS_TRANSFERRED, self::STATUS_DISPUTED];

    public const CERTIFICATE_TYPES = ['SHM', 'HGB', 'HGU', 'Hak Pakai', 'other'];

    protected $fillable = [
        'uuid', 'certificate_type', 'certificate_number', 'nib', 'address',
        'area_m2', 'njop_reference', 'current_owner_partner_id', 'status',
    ];

    protected $casts = [
        'area_m2' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (LandObject $object) {
            if (empty($object->uuid)) {
                $object->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('certificate_number', 'ilike', '%'.$search.'%')
                    ->orWhere('address', 'ilike', '%'.$search.'%')
                    ->orWhere('nib', 'ilike', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function currentOwner()
    {
        return $this->belongsTo(Partner::class, 'current_owner_partner_id');
    }

    public function dueDiligenceChecks()
    {
        return $this->hasMany(DueDiligenceCheck::class);
    }
}
