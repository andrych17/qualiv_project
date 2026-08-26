<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Models\PurRfxHdr;
use App\Modules\Purchase\Models\PurRfxInvitation;
use App\Modules\Purchase\Requests\RecordRfxResponseRequest;
use App\Modules\Purchase\Requests\StoreRfxRequest;
use App\Modules\Purchase\Services\SourcingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourcingController extends Controller
{
    public function __construct(
        protected SourcingService $service,
    ) {}

    public function index(): Response
    {
        $rfxList = PurRfxHdr::query()
            ->with(['requisition:id,pr_no', 'creator:id,name', 'invitations.supplier:id,name'])
            ->withCount(['lines', 'invitations'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurRfxHdr $r) => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'rfx_no' => $r->rfx_no,
                'type' => strtoupper($r->type),
                'pr_no' => $r->requisition?->pr_no,
                'due_date' => $r->due_date->toDateString(),
                'status' => $r->status,
                'lines_count' => $r->lines_count,
                'suppliers_count' => $r->invitations_count,
                'responses_count' => $r->invitations->filter(fn ($i) => $i->responded_at !== null)->count(),
                'creator_name' => $r->creator?->name,
                'created_at' => $r->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchase/Sourcing/Index', [
            'rfxList' => $rfxList,
        ]);
    }

    public function create(Request $request): Response
    {
        $prId = $request->query('pr_id');
        $selectedPr = null;

        if ($prId) {
            $selectedPr = PurRequisitionHdr::with('lines')->find($prId);
        }

        return Inertia::render('Purchase/Sourcing/Create', [
            'selectedPr' => $selectedPr ? [
                'id' => $selectedPr->id,
                'pr_no' => $selectedPr->pr_no,
                'lines' => $selectedPr->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->quantity,
                ]),
            ] : null,
            'approvedPrs' => PurRequisitionHdr::query()
                ->where('status', PurRequisitionHdr::STATUS_APPROVED)
                ->orderByDesc('id')
                ->get(['id', 'pr_no', 'estimated_total']),
            'vendors' => Partner::query()
                ->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(StoreRfxRequest $request)
    {
        $rfx = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('purchase.sourcing.show', $rfx->id)->with('success', "RFQ {$rfx->rfx_no} created.");
    }

    public function show(PurRfxHdr $sourcing): Response
    {
        $sourcing->load([
            'requisition:id,pr_no',
            'creator:id,name',
            'lines.awardedSupplier:id,name',
            'invitations.supplier:id,name',
            'invitations.response.lines',
        ]);

        // Build Side-by-Side Comparison Matrix (§3C)
        $suppliers = $sourcing->invitations->map(fn (PurRfxInvitation $inv) => [
            'invitation_id' => $inv->id,
            'supplier_id' => $inv->supplier_id,
            'supplier_name' => $inv->supplier?->name,
            'responded' => $inv->responded_at !== null,
            'responded_at' => $inv->responded_at?->toDateTimeString(),
            'notes' => $inv->response?->notes,
        ])->values();

        $comparisonLines = $sourcing->lines->map(function ($line) use ($sourcing) {
            $quotes = [];
            $prices = [];

            foreach ($sourcing->invitations as $inv) {
                $quoteLine = $inv->response?->lines->firstWhere('rfx_line_id', $line->id);
                $price = $quoteLine ? (float) $quoteLine->price : null;

                if ($price !== null) {
                    $prices[] = $price;
                }

                $quotes[$inv->supplier_id] = [
                    'price' => $price,
                    'lead_time_days' => $quoteLine?->lead_time_days,
                    'notes' => $quoteLine?->notes,
                ];
            }

            $minPrice = count($prices) > 0 ? min($prices) : null;

            return [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'awarded_supplier_id' => $line->awarded_supplier_id,
                'awarded_supplier_name' => $line->awardedSupplier?->name,
                'quotes' => $quotes,
                'min_price' => $minPrice,
            ];
        });

        return Inertia::render('Purchase/Sourcing/Show', [
            'rfx' => [
                'id' => $sourcing->id,
                'uuid' => $sourcing->uuid,
                'rfx_no' => $sourcing->rfx_no,
                'type' => strtoupper($sourcing->type),
                'pr_id' => $sourcing->pr_id,
                'pr_no' => $sourcing->requisition?->pr_no,
                'due_date' => $sourcing->due_date->toDateString(),
                'status' => $sourcing->status,
                'creator_name' => $sourcing->creator?->name,
                'created_at' => $sourcing->created_at?->toDateTimeString(),
            ],
            'suppliers' => $suppliers,
            'comparisonLines' => $comparisonLines,
        ]);
    }

    public function send(PurRfxHdr $sourcing)
    {
        $this->service->sendToSuppliers($sourcing);

        return redirect()->back()->with('success', "RFQ {$sourcing->rfx_no} sent to invited suppliers.");
    }

    public function recordResponse(RecordRfxResponseRequest $request, PurRfxHdr $sourcing)
    {
        $invitation = PurRfxInvitation::where('id', $request->input('invitation_id'))
            ->where('rfx_id', $sourcing->id)
            ->firstOrFail();

        $quotes = [];
        foreach ($request->input('quotes') as $q) {
            $quotes[$q['rfx_line_id']] = [
                'price' => $q['price'],
                'lead_time_days' => $q['lead_time_days'] ?? null,
                'notes' => $q['notes'] ?? null,
            ];
        }

        $this->service->recordResponse($invitation, $quotes, $request->input('notes'));

        return redirect()->back()->with('success', 'Supplier response recorded.');
    }

    public function award(Request $request, PurRfxHdr $sourcing)
    {
        $request->validate([
            'awards' => ['required', 'array', 'min:1'],
            'awards.*' => ['required', 'integer'],
        ]);

        $partnerIds = array_values($request->input('awards'));
        if (Partner::query()->whereIn('id', $partnerIds)->count() !== count(array_unique($partnerIds))) {
            return redirect()->back()->withErrors(['awards' => 'One or more selected award suppliers are invalid.']);
        }

        $orders = $this->service->awardAndGenerateOrders(
            $sourcing,
            $request->input('awards'),
            $request->user()->id
        );

        $poNumbers = collect($orders)->pluck('po_no')->join(', ');

        return redirect()->back()->with('success', "RFQ awarded. Generated Purchase Order(s): {$poNumbers}");
    }

    public function cancel(PurRfxHdr $sourcing)
    {
        $this->service->cancel($sourcing);

        return redirect()->back()->with('success', "RFQ {$sourcing->rfx_no} cancelled.");
    }
}
