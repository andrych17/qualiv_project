<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurVendorDocument extends Model
{
    protected $table = 'PURCHASE.pur_vendor_documents';

    protected $fillable = ['vendor_profile_id', 'doc_type', 'title', 'dms_document_id', 'expiry_date'];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    public function vendorProfile()
    {
        return $this->belongsTo(VendorProfile::class);
    }
}
