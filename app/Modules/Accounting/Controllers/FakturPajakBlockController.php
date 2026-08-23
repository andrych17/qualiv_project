<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FakturPajakNumberBlock;
use App\Modules\Accounting\Requests\StoreFakturPajakBlockRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\FakturPajakNumberBlockService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3M — tenant-entered DJP Faktur Pajak number-allocation blocks. */
class FakturPajakBlockController extends Controller
{
    public function __construct(private readonly FakturPajakNumberBlockService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $blocks = FakturPajakNumberBlock::query()->where('company_id', $companyId)->orderByDesc('id')->get();

        return Inertia::render('Accounting/FakturBlocks/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'blocks' => $blocks->map(fn (FakturPajakNumberBlock $b) => [
                'id' => $b->id,
                'prefix' => $b->prefix,
                'range_start' => $b->range_start,
                'range_end' => $b->range_end,
                'last_issued' => $b->last_issued,
                'remaining' => $b->remaining(),
                'is_active' => $b->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/FakturBlocks/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $request->integer('company_id') ?: null,
        ]);
    }

    public function store(StoreFakturPajakBlockRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.faktur-blocks.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Faktur Pajak number block created.');
    }

    public function deactivate(FakturPajakNumberBlock $block)
    {
        $companyId = $block->company_id;
        $this->service->deactivate($block);

        return redirect()->route('accounting.faktur-blocks.index', ['company_id' => $companyId])->with('success', 'Block deactivated.');
    }
}
