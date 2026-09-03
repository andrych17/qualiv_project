<?php

namespace App\Modules\POS\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\POS\Models\PosModifier;
use App\Modules\POS\Models\PosModifierGroup;
use App\Modules\POS\Models\PosOverrideLog;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use App\Modules\POS\Models\PosTxnLineModifier;
use App\Modules\POS\Models\PosWeightedBarcodeTemplate;
use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3E, §3F, §3G, §3H — POS Cart Engine, Barcode Scanning, and Line Management.
 */
class PosCartService
{
    public function __construct(
        protected PosSupervisorService $supervisorService,
    ) {}

    /**
     * Scans a barcode and returns product info, resolved price, and detected quantity multiplier.
     */
    public function scanBarcode(string $barcode, int $terminalId): array
    {
        $barcode = trim($barcode);
        $terminal = PosTerminal::query()->with('profile')->findOrFail($terminalId);

        // 1. Check weighted barcode template (§3E)
        $weightedTemplates = PosWeightedBarcodeTemplate::query()->where('is_active', true)->get();
        foreach ($weightedTemplates as $tpl) {
            $prefixLen = strlen($tpl->prefix_from);
            $barcodePrefix = substr($barcode, 0, $prefixLen);

            if ($barcodePrefix >= $tpl->prefix_from && $barcodePrefix <= $tpl->prefix_to && strlen($barcode) >= ($tpl->value_start + $tpl->value_length)) {
                $itemCode = substr($barcode, $tpl->item_code_start, $tpl->item_code_length);
                $rawVal = (float) substr($barcode, $tpl->value_start, $tpl->value_length);
                $scaledVal = $rawVal / pow(10, $tpl->decimal_places);

                // Look up by SKU or PLU
                $product = Product::query()
                    ->where('sku', $itemCode)
                    ->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $itemCode))
                    ->first();

                if ($product) {
                    $unitPrice = $this->resolveProductPrice($product->id, $terminal->default_price_list_id);
                    $qty = $tpl->value_type === PosWeightedBarcodeTemplate::VALUE_TYPE_WEIGHT ? $scaledVal : ($unitPrice > 0 ? round($scaledVal / $unitPrice, 4) : 1);

                    return [
                        'product' => $product,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'uom_code' => $product->baseUom?->code ?? 'KG',
                        'is_weighted' => true,
                        'barcode' => $barcode,
                    ];
                }
            }
        }

        // 2. Check INVENTORY.product_barcodes (§3E)
        $barcodeRow = DB::table('INVENTORY.product_barcodes')
            ->where('barcode', $barcode)
            ->first();

        if ($barcodeRow) {
            $product = Product::query()->with('baseUom')->find($barcodeRow->product_id);
            if ($product) {
                $unitPrice = $this->resolveProductPrice($product->id, $terminal->default_price_list_id);
                $multiplier = (float) ($barcodeRow->unit_multiplier ?? 1);

                return [
                    'product' => $product,
                    'qty' => $multiplier > 0 ? $multiplier : 1.0,
                    'unit_price' => $unitPrice,
                    'uom_code' => $product->baseUom?->code ?? 'EA',
                    'is_weighted' => false,
                    'barcode' => $barcode,
                    'barcode_type' => $barcodeRow->type,
                ];
            }
        }

        // 3. Fallback: Lookup product SKU directly
        $product = Product::query()->with('baseUom')->where('sku', $barcode)->first();
        if ($product) {
            $unitPrice = $this->resolveProductPrice($product->id, $terminal->default_price_list_id);

            return [
                'product' => $product,
                'qty' => 1.0,
                'unit_price' => $unitPrice,
                'uom_code' => $product->baseUom?->code ?? 'EA',
                'is_weighted' => false,
                'barcode' => $barcode,
            ];
        }

        throw ValidationException::withMessages([
            'barcode' => ["Barcode or product SKU '{$barcode}' not found in inventory."],
        ]);
    }

    public function createDraftTransaction(int $sessionId, array $data = []): PosTxnHdr
    {
        return DB::transaction(function () use ($sessionId, $data) {
            $session = PosSession::query()->with('terminal')->findOrFail($sessionId);

            if ($session->status !== PosSession::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'session_id' => ['Cannot create transaction in a closed session.'],
                ]);
            }

            $terminal = $session->terminal;
            $terminal->increment('last_local_seq');
            $terminal->refresh();

            $seqPadded = str_pad((string) $terminal->last_local_seq, 6, '0', STR_PAD_LEFT);
            $receiptNumber = "{$terminal->receipt_prefix}-{$seqPadded}";

            return PosTxnHdr::query()->create([
                'client_txn_uuid' => $data['client_txn_uuid'] ?? (string) Str::uuid(),
                'session_id' => $sessionId,
                'terminal_id' => $terminal->id,
                'receipt_number' => $receiptNumber,
                'customer_id' => $data['customer_id'] ?? null,
                'table_id' => $data['table_id'] ?? null,
                'dining_mode' => $data['dining_mode'] ?? PosTxnHdr::DINING_SALE,
                'price_list_id' => $data['price_list_id'] ?? $terminal->default_price_list_id,
                'status' => PosTxnHdr::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'park_label' => $data['park_label'] ?? null,
                'created_offline' => (bool) ($data['created_offline'] ?? false),
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);
        });
    }

    public function addLine(PosTxnHdr $txn, array $lineData): PosTxnLine
    {
        return DB::transaction(function () use ($txn, $lineData) {
            if (! in_array($txn->status, [PosTxnHdr::STATUS_DRAFT, PosTxnHdr::STATUS_PARKED])) {
                throw ValidationException::withMessages([
                    'txn' => ['Cannot modify lines of a completed or voided transaction.'],
                ]);
            }

            $nextLineNo = (int) PosTxnLine::query()->where('txn_id', $txn->id)->max('line_no') + 1;

            $isOpenItem = (bool) ($lineData['is_open_item'] ?? false);
            $productId = $lineData['product_id'] ?? null;
            $description = $lineData['description'] ?? null;

            if ($isOpenItem) {
                if (empty($description)) {
                    throw ValidationException::withMessages(['description' => ['Description required for open item.']]);
                }
                $unitPrice = (float) ($lineData['unit_price'] ?? 0);
            } else {
                $product = Product::query()->findOrFail($productId);
                $description = $description ?: $product->name;
                $unitPrice = (float) ($lineData['unit_price'] ?? $this->resolveProductPrice($product->id, $txn->price_list_id));
            }

            $qty = (float) ($lineData['qty'] ?? 1);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['qty' => ['Quantity must be greater than zero.']]);
            }

            // Calculate modifiers (§3N)
            $modifierIds = $lineData['modifier_ids'] ?? [];
            $modifiers = PosModifier::query()->whereIn('id', $modifierIds)->get();

            // Validate modifier group min/max selection
            $this->validateModifiers($productId, $modifiers);

            $modifierDelta = 0.0;
            foreach ($modifiers as $mod) {
                if ($mod->replaces_base_price) {
                    $unitPrice = (float) $mod->price_delta;
                } else {
                    $modifierDelta += (float) $mod->price_delta;
                }
            }

            $effectiveUnitPrice = $unitPrice + $modifierDelta;
            $discountAmount = (float) ($lineData['discount_amount'] ?? 0);
            $taxAmount = (float) ($lineData['tax_amount'] ?? 0);
            $lineTotal = ($qty * $effectiveUnitPrice) - $discountAmount + $taxAmount;

            $line = PosTxnLine::query()->create([
                'txn_id' => $txn->id,
                'line_no' => $nextLineNo,
                'product_id' => $productId,
                'is_open_item' => $isOpenItem,
                'description' => $description,
                'uom_code' => $lineData['uom_code'] ?? 'EA',
                'qty' => $qty,
                'unit_price' => $effectiveUnitPrice,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'batch_id' => $lineData['batch_id'] ?? null,
                'serial_id' => $lineData['serial_id'] ?? null,
                'course' => $lineData['course'] ?? null,
                'seat_number' => $lineData['seat_number'] ?? null,
                'special_instruction' => $lineData['special_instruction'] ?? null,
                'kitchen_note' => $lineData['kitchen_note'] ?? null,
            ]);

            foreach ($modifiers as $mod) {
                PosTxnLineModifier::query()->create([
                    'txn_line_id' => $line->id,
                    'modifier_id' => $mod->id,
                    'modifier_name' => $mod->name,
                    'price_delta' => $mod->price_delta,
                ]);
            }

            $this->recalculateTxnTotals($txn);

            return $line->refresh()->load('modifiers');
        });
    }

    public function removeLine(PosTxnHdr $txn, int $lineId): void
    {
        DB::transaction(function () use ($txn, $lineId) {
            PosTxnLine::query()->where('txn_id', $txn->id)->where('id', $lineId)->delete();
            $this->recalculateTxnTotals($txn);
        });
    }

    public function applyHeaderDiscount(PosTxnHdr $txn, float $discountAmount, ?string $supervisorPin = null, ?int $userId = null): void
    {
        $subtotal = (float) $txn->subtotal;
        if ($subtotal <= 0) {
            return;
        }

        $pct = ($discountAmount / $subtotal) * 100;
        $threshold = (float) ConfigConst::query()
            ->where('const_group', 'POS')
            ->where('group_code', 'POS_DISCOUNT_PIN_ABOVE')
            ->value('value') ?: 10.0;

        if ($pct > $threshold) {
            if (! $supervisorPin) {
                throw ValidationException::withMessages([
                    'discount' => ["Discount of {$pct}% exceeds supervisor threshold ({$threshold}%). PIN required."],
                ]);
            }
            $authUserId = $this->supervisorService->verifyPinAndGetUserId($supervisorPin);
            if (! $authUserId) {
                throw ValidationException::withMessages([
                    'supervisor_pin' => ['Invalid supervisor PIN for elevated discount.'],
                ]);
            }
            $this->supervisorService->recordOverride(
                $userId ?: auth()->id() ?: 1,
                $authUserId,
                PosOverrideLog::ACTION_DISCOUNT,
                $txn->id,
                $txn->session_id,
                "Discount of {$discountAmount} ({$pct}%) applied"
            );
        }

        $txn->update(['discount_total' => $discountAmount]);
        $this->recalculateTxnTotals($txn);
    }

    public function parkTransaction(int $txnId, ?string $label = null): PosTxnHdr
    {
        $txn = PosTxnHdr::query()->findOrFail($txnId);
        $txn->update([
            'status' => PosTxnHdr::STATUS_PARKED,
            'park_label' => $label ?: "Parked #{$txn->id}",
        ]);

        return $txn->refresh();
    }

    public function resumeTransaction(int $txnId): PosTxnHdr
    {
        $txn = PosTxnHdr::query()->findOrFail($txnId);
        $txn->update(['status' => PosTxnHdr::STATUS_DRAFT]);

        return $txn->refresh();
    }

    public function voidTransaction(int $txnId, int $userId, ?string $reason = null, ?string $supervisorPin = null): PosTxnHdr
    {
        $txn = PosTxnHdr::query()->findOrFail($txnId);

        if ($txn->status === PosTxnHdr::STATUS_COMPLETED) {
            if (! $supervisorPin) {
                throw ValidationException::withMessages([
                    'supervisor_pin' => ['Voiding a completed sale requires supervisor PIN.'],
                ]);
            }
            $authUserId = $this->supervisorService->verifyPinAndGetUserId($supervisorPin);
            if (! $authUserId) {
                throw ValidationException::withMessages(['supervisor_pin' => ['Invalid supervisor PIN.']]);
            }
            $this->supervisorService->recordOverride(
                $userId,
                $authUserId,
                PosOverrideLog::ACTION_SALE_VOID,
                $txn->id,
                $txn->session_id,
                $reason
            );
        }

        $txn->update([
            'status' => PosTxnHdr::STATUS_VOIDED,
            'notes' => $reason ? ($txn->notes ? $txn->notes." | Void: {$reason}" : "Void: {$reason}") : $txn->notes,
        ]);

        return $txn->refresh();
    }

    public function recalculateTxnTotals(PosTxnHdr $txn): void
    {
        $subtotal = (float) PosTxnLine::query()->where('txn_id', $txn->id)->sum('line_total');
        $discountTotal = (float) $txn->discount_total;
        $taxTotal = (float) $txn->tax_total;
        $serviceCharge = (float) $txn->service_charge;
        $rounding = (float) $txn->rounding;

        $grandTotal = max(0, $subtotal - $discountTotal + $taxTotal + $serviceCharge + $rounding);

        $txn->update([
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
        ]);
    }

    protected function resolveProductPrice(int $productId, ?int $priceListId): float
    {
        if ($priceListId) {
            $line = DB::table('SALES.price_list_lines')
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->first();

            if ($line) {
                return (float) $line->price;
            }
        }

        // Fallback to product standard_cost or a default price
        $product = Product::query()->find($productId);

        return (float) ($product?->standard_cost ?? 10000.0);
    }

    protected function validateModifiers(?int $productId, $modifiers): void
    {
        if (! $productId || $modifiers->isEmpty()) {
            return;
        }

        $modifierGroups = PosModifierGroup::query()
            ->whereHas('products', fn ($q) => $q->where('product_id', $productId))
            ->with('modifiers')
            ->get();

        foreach ($modifierGroups as $group) {
            $selectedCount = $modifiers->where('group_id', $group->id)->count();
            if ($group->min_selections > 0 && $selectedCount < $group->min_selections) {
                throw ValidationException::withMessages([
                    'modifiers' => ["Modifier group '{$group->name}' requires at least {$group->min_selections} selections."],
                ]);
            }
            if ($group->max_selections > 0 && $selectedCount > $group->max_selections) {
                throw ValidationException::withMessages([
                    'modifiers' => ["Modifier group '{$group->name}' allows at most {$group->max_selections} selections."],
                ]);
            }
        }
    }
}
