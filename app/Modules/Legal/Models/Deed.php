<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Deed extends Model
{
    protected $table = 'LEGAL.deeds';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY_FOR_SIGNING = 'ready_for_signing';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_READY_FOR_SIGNING, self::STATUS_SIGNED, self::STATUS_ARCHIVED];

    /** Forward-only lifecycle (§3C) — no skipping, no going back. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_READY_FOR_SIGNING],
        self::STATUS_READY_FOR_SIGNING => [self::STATUS_SIGNED],
        self::STATUS_SIGNED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    protected $fillable = [
        'uuid', 'matter_id', 'deed_type_id', 'category', 'deed_number',
        'status', 'signing_date', 'minuta_reference', 'summary', 'amends_deed_id',
        'land_object_id', 'transaction_value',
    ];

    protected $casts = [
        'signing_date' => 'date',
        'transaction_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Deed $deed) {
            if (empty($deed->uuid)) {
                $deed->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('deed_number', 'ilike', '%'.$search.'%')
                    ->orWhere('minuta_reference', 'ilike', '%'.$search.'%')
                    ->orWhere('summary', 'ilike', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    /** Signed and archived deeds are the statutory immutable record (§5 deed immutability). */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_SIGNED, self::STATUS_ARCHIVED], true);
    }

    public function matter()
    {
        return $this->belongsTo(Matter::class);
    }

    public function deedType()
    {
        return $this->belongsTo(DeedType::class);
    }

    public function amendsDeed()
    {
        return $this->belongsTo(self::class, 'amends_deed_id');
    }

    public function parties()
    {
        return $this->hasMany(DeedParty::class);
    }

    public function landObject()
    {
        return $this->belongsTo(LandObject::class);
    }

    public function taxes()
    {
        return $this->hasMany(DeedTax::class);
    }

    public function bpnSubmissions()
    {
        return $this->hasMany(BpnSubmission::class);
    }

    public function will()
    {
        return $this->hasOne(Will::class);
    }

    public function scopeNotary(Builder $query): void
    {
        $query->where('category', DeedType::CATEGORY_NOTARY);
    }

    public function scopePpat(Builder $query): void
    {
        $query->where('category', DeedType::CATEGORY_PPAT);
    }
}
