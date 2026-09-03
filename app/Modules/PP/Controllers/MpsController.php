<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\MpsHeader;
use App\Modules\PP\Models\MpsLine;
use App\Modules\PP\Requests\StoreMpsHeaderRequest;
use App\Modules\PP\Requests\UpdateMpsLineQtyRequest;
use App\Modules\PP\Services\MpsService;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3C — Master Production Schedule grid: presentation plus firm/release actions over §3D's planned orders. */
class MpsController extends Controller
{
    public function __construct(protected MpsService $mps) {}

    public function index(): Response
    {
        $periods = $this->mps->periods();

        $headers = MpsHeader::query()->baseline()
            ->with(['product:id,sku,name', 'lines'])
            ->get();

        $rows = $headers->map(function (MpsHeader $header) {
            return [
                'header_id' => $header->id,
                'product_id' => $header->product_id,
                'product_sku' => $header->product?->sku,
                'product_name' => $header->product?->name,
                'cells' => $header->lines->keyBy(fn (MpsLine $line) => $line->period_start->toDateString())
                    ->map(fn (MpsLine $line) => [
                        'id' => $line->id,
                        'planned_qty' => (float) $line->planned_qty,
                        'is_frozen' => $line->is_frozen,
                        'released_planned_order_id' => $line->released_planned_order_id,
                        ...$this->mps->drillDown($line),
                    ]),
            ];
        });

        return Inertia::render('PP/Mps/Index', [
            'periods' => $periods,
            'rows' => $rows,
        ]);
    }

    public function store(StoreMpsHeaderRequest $request)
    {
        $this->mps->getOrCreateHeader((int) $request->validated('product_id'));

        return redirect()->route('pp.mps.index')->with('success', 'Product added to the MPS grid.');
    }

    public function destroy(MpsHeader $mpsHeader)
    {
        $this->mps->removeHeader($mpsHeader);

        return redirect()->route('pp.mps.index')->with('success', 'Product removed from the MPS grid.');
    }

    public function updateQty(UpdateMpsLineQtyRequest $request, MpsLine $mpsLine)
    {
        try {
            $this->mps->updateQty($mpsLine, (float) $request->validated('planned_qty'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Planned quantity updated.');
    }

    public function toggleFreeze(MpsLine $mpsLine)
    {
        $this->mps->setFrozen($mpsLine, ! $mpsLine->is_frozen);

        return back()->with('success', $mpsLine->is_frozen ? 'Period unfrozen.' : 'Period frozen.');
    }

    public function firm(MpsLine $mpsLine)
    {
        try {
            $this->mps->firm($mpsLine);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Planned order firmed — excluded from the next MRP run.');
    }

    public function unfirm(MpsLine $mpsLine)
    {
        try {
            $this->mps->unfirm($mpsLine);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Planned order unfirmed.');
    }

    public function release(MpsLine $mpsLine)
    {
        try {
            $this->mps->release($mpsLine);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Planned order released.');
    }
}
