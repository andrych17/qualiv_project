<?php

namespace App\Modules\MES\Requests;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\RoutingOp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3J — one `issue`/`return` movement against a released production order. */
class StoreMaterialConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_product_id' => 'required|integer',
            'type' => ['required', Rule::in([MaterialConsumption::TYPE_ISSUE, MaterialConsumption::TYPE_RETURN])],
            'qty' => 'required|numeric|min:0.0001',
            'uom_code' => 'nullable|string|max:10',
            'operation_ref' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'lot_id' => 'nullable|integer',
            'serial_number' => 'nullable|string|max:100',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ProdOrder $order */
            $order = $this->route('prodOrder') ?? $this->route('prod_order');

            $productId = $this->input('material_product_id');
            $product = $productId ? Product::query()->find($productId) : null;

            if ($productId && ! $product) {
                $validator->errors()->add('material_product_id', 'The selected material is invalid.');

                return;
            }

            if ($this->input('type') === MaterialConsumption::TYPE_ISSUE && ! $this->input('location_id')) {
                $validator->errors()->add('location_id', 'A source location is required to issue material.');
            }

            $locationId = $this->input('location_id');
            if ($locationId && $order && ! Location::query()->where('id', $locationId)->where('warehouse_id', $order->warehouse_id)->exists()) {
                $validator->errors()->add('location_id', 'The selected location does not belong to this order\'s warehouse.');
            }

            $lotId = $this->input('lot_id');
            if ($lotId && (! $product || ! StockBatch::query()->where('id', $lotId)->where('product_id', $product->id)->exists())) {
                $validator->errors()->add('lot_id', 'The selected lot does not belong to this material.');
            }

            if ($product && $product->tracking_mode === Product::TRACKING_BATCH && ! $lotId) {
                $validator->errors()->add('lot_id', "{$product->sku} is batch-tracked — select a lot.");
            }

            if ($product && $product->tracking_mode === Product::TRACKING_SERIAL) {
                if (empty($this->input('serial_number'))) {
                    $validator->errors()->add('serial_number', "{$product->sku} is serial-tracked — enter the serial number.");
                }
                if ((float) $this->input('qty', 0) !== 1.0) {
                    $validator->errors()->add('qty', 'A serial-tracked movement can only cover one unit at a time.');
                }
                if ($this->input('type') === MaterialConsumption::TYPE_RETURN) {
                    $validator->errors()->add('serial_number', 'Returning a serial-tracked component is not yet supported — see MES_SPECS.md §3J.');
                }
            }

            $operationRef = $this->input('operation_ref');
            if ($operationRef && $order && $order->production_model === ProdOrder::MODEL_ASSEMBLY) {
                if (! RoutingOp::query()->where('id', $operationRef)->where('routing_id', $order->routing_id)->exists()) {
                    $validator->errors()->add('operation_ref', 'The selected operation does not belong to this order\'s routing.');
                }
            }
        });
    }
}
