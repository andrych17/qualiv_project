<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CoretaxExportBatch;
use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\Accounting\Requests\GenerateCoretaxExportRequest;
use App\Modules\Accounting\Services\CoretaxExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** §3M Coretax export — generates a structured-XML batch for bulk import into Coretax (see XmlCoretaxExportDriver docblock for the "not yet DJP-schema-verified" caveat). */
class CoretaxExportController extends Controller
{
    public function __construct(private readonly CoretaxExportService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $batches = CoretaxExportBatch::query()->where('company_id', $companyId)->with(['taxPeriod:id,obligation_type,masa_pajak', 'generatedBy:id,name'])->orderByDesc('id')->get();
        $periods = TaxPeriod::query()->where('company_id', $companyId)->orderByDesc('masa_pajak')->get(['id', 'obligation_type', 'masa_pajak']);

        return Inertia::render('Accounting/CoretaxExports/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'periods' => $periods->map(fn (TaxPeriod $p) => ['value' => $p->id, 'label' => "{$p->masa_pajak} ({$p->obligation_type})"]),
            'batches' => $batches->map(fn (CoretaxExportBatch $b) => [
                'id' => $b->id,
                'batch_type' => $b->batch_type,
                'masa_pajak' => $b->taxPeriod?->masa_pajak,
                'record_count' => $b->record_count,
                'generated_by' => $b->generatedBy?->name,
                'generated_at' => $b->generated_at->toDateTimeString(),
            ]),
        ]);
    }

    public function store(GenerateCoretaxExportRequest $request)
    {
        $data = $request->validated();
        $company = Company::query()->findOrFail($data['company_id']);
        $period = TaxPeriod::query()->findOrFail($data['tax_period_id']);

        $this->service->generate($company, $period, $data['batch_type'], $request->user()->id);

        return redirect()->route('accounting.coretax-exports.index', ['company_id' => $data['company_id']])
            ->with('success', 'Coretax export batch generated.');
    }

    public function download(CoretaxExportBatch $batch)
    {
        return Storage::disk('objects')->download($batch->object_key, basename($batch->object_key));
    }
}
