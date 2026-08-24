<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Requests\StoreAssetDisposalRequest;
use App\Modules\Accounting\Services\AssetDisposalService;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** §3G — sale/write-off, a one-shot form (create+store run together, same "form is the review step" posture as ArPaymentController). */
class AssetDisposalController extends Controller
{
    public function __construct(private readonly AssetDisposalService $service) {}

    public function create(FixedAsset $asset): Response
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw ValidationException::withMessages(['asset' => 'This asset is already disposed.']);
        }

        return Inertia::render('Accounting/FixedAssets/Dispose', [
            'asset' => $asset->only(['id', 'company_id', 'asset_no', 'name', 'acquisition_cost']),
            'accounts' => Account::query()
                ->where('company_id', $asset->company_id)->where('is_active', true)->where('is_control_account', false)
                ->orderBy('account_code')->get(['id', 'account_code', 'account_name'])
                ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} {$a->account_name}"])
                ->values(),
        ]);
    }

    public function store(StoreAssetDisposalRequest $request, FixedAsset $asset)
    {
        $this->service->dispose($asset, $request->validated(), $request->user()->id);

        return redirect()->route('accounting.fixed-assets.show', $asset->id)->with('success', 'Asset disposed.');
    }
}
