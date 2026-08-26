<?php

namespace App\Modules\Purchase\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class VendorProfile extends Model
{
    protected $table = 'PURCHASE.vendor_profiles';

    protected $fillable = [
        'partner_id', 'payment_terms_days', 'incoterms', 'preferred_currency',
        'tax_registration_no', 'bank_name', 'bank_account',
        'is_preferred', 'onboarding_status',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
    ];

    protected $hidden = ['bank_account_encrypted'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function documents()
    {
        return $this->hasMany(PurVendorDocument::class);
    }

    public function setBankAccountAttribute(?string $value): void
    {
        $this->attributes['bank_account_encrypted'] = $value === null ? null : encrypt($value);
    }

    public function getBankAccountAttribute(): ?string
    {
        return $this->bank_account_encrypted ? decrypt($this->bank_account_encrypted) : null;
    }
}
