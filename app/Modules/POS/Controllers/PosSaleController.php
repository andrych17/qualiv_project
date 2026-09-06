<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosFavoriteItem;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Services\PosCartService;
use App\Modules\POS\Services\PosPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS_SPECS.md §3E, §3F, §3I — POS Cashier Register Controller.
 */
class PosSaleController extends Controller
{
    public function __construct(
        protected PosCartService $cartService,
        protected PosPaymentService $paymentService,
    ) {}

    public function index(Request $request): Response
    {
        $terminals = PosTerminal::query()->where('is_active', true)->with('profile')->get();
        $terminalId = $request->input('terminal_id', $terminals->first()?->id);

        $activeSession = null;
        $favorites = [];
        $parkedOrders = [];
        $tables = [];

        if ($terminalId) {
            $terminal = $terminals->firstWhere('id', $terminalId);

            $activeSession = PosSession::query()
                ->where('terminal_id', $terminalId)
                ->where('status', PosSession::STATUS_OPEN)
                ->with('terminal.profile')
                ->first();

            $priceListId = $terminal?->default_price_list_id
                ?: DB::table('SALES.price_lists')->where('is_tenant_default', true)->value('id');

            $priceMap = $priceListId
                ? DB::table('SALES.price_list_lines')
                    ->where('price_list_id', $priceListId)
                    ->whereNotNull('product_id')
                    ->pluck('unit_price', 'product_id')
                : collect();

            $favorites = PosFavoriteItem::query()
                ->where('terminal_id', $terminalId)
                ->with('product.baseUom')
                ->orderBy('sort_order')
                ->get()
                ->each(function ($fav) use ($priceMap) {
                    if ($fav->product) {
                        $fav->product->default_price = (float) ($priceMap[$fav->product->id] ?? 0);
                        $fav->product->code = $fav->product->sku;
                    }
                });

            if ($activeSession) {
                $parkedOrders = PosTxnHdr::query()
                    ->where('session_id', $activeSession->id)
                    ->where('status', PosTxnHdr::STATUS_PARKED)
                    ->with(['lines', 'table'])
                    ->get();
            }

            $tables = PosTable::query()->with('activeTransaction')->get();
        }

        return Inertia::render('POS/Sale/Index', [
            'terminals' => $terminals,
            'selectedTerminalId' => $terminalId,
            'activeSession' => $activeSession,
            'favorites' => $favorites,
            'parkedOrders' => $parkedOrders,
            'tables' => $tables,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string'],
            'terminal_id' => ['required', 'integer'],
        ]);

        $item = $this->cartService->scanBarcode($validated['barcode'], (int) $validated['terminal_id']);

        if (isset($item['product']) && is_object($item['product'])) {
            $product = $item['product'];
            $item['id'] = $product->id;
            $item['product_id'] = $product->id;
            $item['code'] = $product->sku;
            $item['name'] = $product->name;
            $item['uom_id'] = $product->base_uom_id;
            $item['price'] = $item['unit_price'];
        }

        return response()->json($item);
    }

    public function createDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'table_id' => ['nullable', 'integer'],
            'dining_mode' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $txn = $this->cartService->createDraftTransaction((int) $validated['session_id'], $validated);

        return response()->json($txn->load(['lines.modifiers', 'table']));
    }

    public function addLine(Request $request, PosTxnHdr $txn): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'is_open_item' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'uom_code' => ['nullable', 'string'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'modifier_ids' => ['nullable', 'array'],
            'special_instruction' => ['nullable', 'string'],
            'seat_number' => ['nullable', 'integer'],
            'course' => ['nullable', 'string'],
        ]);

        $line = $this->cartService->addLine($txn, $validated);

        return response()->json([
            'line' => $line,
            'transaction' => $txn->fresh()->load('lines.modifiers'),
        ]);
    }

    public function removeLine(PosTxnHdr $txn, int $lineId): JsonResponse
    {
        $this->cartService->removeLine($txn, $lineId);

        return response()->json($txn->fresh()->load('lines.modifiers'));
    }

    public function applyDiscount(Request $request, PosTxnHdr $txn): JsonResponse
    {
        $validated = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'supervisor_pin' => ['nullable', 'string'],
        ]);

        $this->cartService->applyHeaderDiscount(
            $txn,
            (float) $validated['discount_amount'],
            $validated['supervisor_pin'] ?? null,
            auth()->id() ?: 1
        );

        return response()->json($txn->fresh());
    }

    public function park(Request $request, PosTxnHdr $txn): JsonResponse
    {
        $label = $request->input('park_label');
        $parked = $this->cartService->parkTransaction($txn->id, $label);

        return response()->json($parked);
    }

    public function resume(PosTxnHdr $txn): JsonResponse
    {
        $resumed = $this->cartService->resumeTransaction($txn->id);

        return response()->json($resumed->load('lines.modifiers'));
    }

    public function void(Request $request, PosTxnHdr $txn): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
            'supervisor_pin' => ['nullable', 'string'],
        ]);

        $voided = $this->cartService->voidTransaction(
            $txn->id,
            auth()->id() ?: 1,
            $validated['reason'] ?? null,
            $validated['supervisor_pin'] ?? null
        );

        return response()->json($voided);
    }

    public function pay(Request $request, PosTxnHdr $txn): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string'],
            'gift_card_id' => ['nullable', 'integer'],
            'store_credit_id' => ['nullable', 'integer'],
        ]);

        $payment = $this->paymentService->addPayment(
            $txn->id,
            $validated['method'],
            (float) $validated['amount'],
            $validated['reference'] ?? null,
            $validated['gift_card_id'] ?? null,
            $validated['store_credit_id'] ?? null
        );

        return response()->json([
            'payment' => $payment,
            'transaction' => $txn->fresh()->load('payments'),
        ]);
    }

    public function complete(PosTxnHdr $txn): JsonResponse
    {
        $completed = $this->paymentService->completeTransaction($txn->id);

        return response()->json($completed->load(['lines.modifiers', 'payments']));
    }
}
