<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\VendorProfile;
use App\Modules\Purchase\Requests\StoreVendorDocumentRequest;
use App\Modules\Purchase\Requests\StoreVendorProfileRequest;
use App\Modules\Purchase\Requests\UpdateVendorProfileRequest;
use App\Modules\Purchase\Services\VendorProfileService;
use Inertia\Inertia;
use Inertia\Response;

class VendorProfileController extends Controller
{
    public function __construct(protected VendorProfileService $service) {}

    public function index(): Response
    {
        $vendors = VendorProfile::query()
            ->with('partner:id,name,type')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VendorProfile $v) => [
                'id' => $v->id,
                'partner_id' => $v->partner_id,
                'partner_name' => $v->partner?->name,
                'payment_terms_days' => $v->payment_terms_days,
                'preferred_currency' => $v->preferred_currency,
                'is_preferred' => $v->is_preferred,
                'onboarding_status' => $v->onboarding_status,
            ]);

        return Inertia::render('Purchase/Vendors/Index', ['vendors' => $vendors]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchase/Vendors/Create', [
            'eligiblePartners' => $this->service->eligiblePartners(),
        ]);
    }

    public function store(StoreVendorProfileRequest $request)
    {
        $vendor = $this->service->create($request->validated());

        return redirect()->route('purchase.vendors.edit', $vendor->id)->with('success', 'Vendor profile created.');
    }

    public function edit(VendorProfile $vendor): Response
    {
        $vendor->load(['partner:id,name,type', 'documents']);

        return Inertia::render('Purchase/Vendors/Edit', [
            'vendor' => [
                'id' => $vendor->id,
                'partner_id' => $vendor->partner_id,
                'partner_name' => $vendor->partner?->name,
                'payment_terms_days' => $vendor->payment_terms_days,
                'incoterms' => $vendor->incoterms,
                'preferred_currency' => $vendor->preferred_currency,
                'tax_registration_no' => $vendor->tax_registration_no,
                'bank_name' => $vendor->bank_name,
                'is_preferred' => $vendor->is_preferred,
                'onboarding_status' => $vendor->onboarding_status,
                'documents' => $vendor->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'doc_type' => $d->doc_type,
                    'title' => $d->title,
                    'expiry_date' => $d->expiry_date?->toDateString(),
                ]),
            ],
        ]);
    }

    public function update(UpdateVendorProfileRequest $request, VendorProfile $vendor)
    {
        $this->service->update($vendor, $request->validated());

        return back()->with('success', 'Vendor profile updated.');
    }

    public function storeDocument(StoreVendorDocumentRequest $request, VendorProfile $vendor)
    {
        $data = $request->validated();
        $this->service->attachDocument($vendor, $data['file'], $data, $request->user()->id);

        return back()->with('success', 'Document attached.');
    }
}
