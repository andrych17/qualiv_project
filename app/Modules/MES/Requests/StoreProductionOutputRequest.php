<?php

namespace App\Modules\MES\Requests;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\RoutingOp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3J — one finished/co-product/by-product/waste output row against a released production order. */
class StoreProductionOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'output_type' => ['required', Rule::in([
                ProductionOutput::TYPE_FINISHED, ProductionOutput::TYPE_CO_PRODUCT,
                ProductionOutput::TYPE_BY_PRODUCT, ProductionOutput::TYPE_WASTE,
            ])],
            'product_id' => 'required|integer',
            'qty' => 'required|numeric|min:0.0001',
            'uom_code' => 'nullable|string|max:10',
            'operation_ref' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'lot_number' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
            'reason_code' => 'nullable|string|max:30',
            'disposition' => ['nullable', Rule::in([ProductionOutput::DISPOSITION_SCRAP, ProductionOutput::DISPOSITION_REWORK])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ProdOrder $order */
            $order = $this->route('prodOrder') ?? $this->route('prod_order');

            $productId = $this->input('product_id');
            $product = $productId ? Product::query()->find($productId) : null;

            if ($productId && ! $product) {
                $validator->errors()->add('product_id', 'The selected product is invalid.');

                return;
            }

            if ($this->input('output_type') === ProductionOutput::TYPE_WASTE && empty($this->input('reason_code'))) {
                $validator->errors()->add('reason_code', 'A reason code is required for waste output.');
            }

            $locationId = $this->input('location_id');
            if ($locationId && $order && ! Location::query()->where('id', $locationId)->where('warehouse_id', $order->warehouse_id)->exists()) {
                $validator->errors()->add('location_id', 'The selected location does not belong to this order\'s warehouse.');
            }

            if ($product && $product->tracking_mode === Product::TRACKING_BATCH && empty($this->input('lot_number'))) {
                $validator->errors()->add('lot_number', "{$product->sku} is batch-tracked — enter a lot number.");
            }

            if ($product && $product->tracking_mode === Product::TRACKING_SERIAL) {
                if (empty($this->input('serial_number'))) {
                    $validator->errors()->add('serial_number', "{$product->sku} is serial-tracked — enter the serial number.");
                }
                if ((float) $this->input('qty', 0) !== 1.0) {
                    $validator->errors()->add('qty', 'A serial-tracked output can only be recorded one unit at a time.');
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
