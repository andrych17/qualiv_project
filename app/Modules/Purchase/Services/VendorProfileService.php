<?php

namespace App\Modules\Purchase\Services;

use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\DMS\Services\DocumentService;
use App\Modules\Purchase\Models\PurVendorDocument;
use App\Modules\Purchase\Models\VendorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VendorProfileService
{
    public function __construct(protected DocumentService $documents) {}

    /** Partners eligible/extended as vendors — active CRM.partners with role VENDOR (§3G). */
    public function eligiblePartners(): Collection
    {
        return Partner::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereHas('roleType', fn ($q) => $q->where('code', 'VENDOR')))
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
    }

    public function create(array $data): VendorProfile
    {
        return DB::transaction(fn () => VendorProfile::create($data));
    }

    public function update(VendorProfile $vendorProfile, array $data): VendorProfile
    {
        DB::transaction(fn () => $vendorProfile->update($data));

        return $vendorProfile->fresh();
    }

    public function attachDocument(VendorProfile $vendorProfile, UploadedFile $file, array $data, int $userId): PurVendorDocument
    {
        return DB::transaction(function () use ($vendorProfile, $file, $data, $userId) {
            $document = $this->documents->upload($file, [
                'title' => $data['title'],
                'subject_type' => 'purchase.vendor_profiles',
                'subject_id' => $vendorProfile->id,
                'expiry_date' => $data['expiry_date'] ?? null,
            ], $userId);

            return $vendorProfile->documents()->create([
                'doc_type' => $data['doc_type'],
                'title' => $data['title'],
                'dms_document_id' => $document->id,
                'expiry_date' => $data['expiry_date'] ?? null,
            ]);
        });
    }

    public static function vendorRoleTypeId(): ?int
    {
        return PartnerRoleType::query()->where('code', 'VENDOR')->value('id');
    }
}
