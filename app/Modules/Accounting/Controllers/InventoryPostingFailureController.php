<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\InventoryPostingFailure;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\InventoryGlPostingService;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** §3H review queue — "fails loudly and queues for review rather than posting to a suspense account silently" (spec rule). Retry re-attempts posting after the underlying mapping/period problem is fixed. */
class InventoryPostingFailureController extends Controller
{
    public function __construct(private readonly InventoryGlPostingService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $failures = InventoryPostingFailure::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->get();

        $items = Product::query()->whereIn('id', $failures->pluck('inventory_item_id')->unique())->get(['id', 'sku', 'name'])->keyBy('id');

        return Inertia::render('Accounting/InventoryPostingFailures/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'failures' => $failures->map(fn (InventoryPostingFailure $f) => [
                'id' => $f->id,
                'event_type' => $f->event_type,
                'item_label' => $items->get($f->inventory_item_id)?->sku.' — '.($items->get($f->inventory_item_id)?->name ?? "item #{$f->inventory_item_id}"),
                'subject_type' => $f->subject_type,
                'subject_id' => $f->subject_id,
                'reason' => $f->reason,
                'status' => $f->status,
                'created_at' => $f->created_at->format('d M Y H:i'),
                'resolved_at' => $f->resolved_at?->format('d M Y H:i'),
            ]),
        ]);
    }

    public function retry(InventoryPostingFailure $failure)
    {
        try {
            $this->service->retry($failure);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $failure->refresh();
        if ($failure->status === InventoryPostingFailure::STATUS_PENDING) {
            return back()->withErrors(['failure' => 'Still failing: '.$failure->reason]);
        }

        return back()->with('success', 'Posted successfully.');
    }
}
