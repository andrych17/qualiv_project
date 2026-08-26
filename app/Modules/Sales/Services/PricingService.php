<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\CustomerSalesProfile;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListLine;
use App\Modules\Sales\Models\PromoCode;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * Resolve the active price list for a customer (or fallback to tenant default).
     */
    public function resolvePriceList(?int $partnerId): ?PriceList
    {
        if ($partnerId) {
            $profile = CustomerSalesProfile::where('partner_id', $partnerId)->first();
            if ($profile && $profile->price_list_id) {
                $priceList = PriceList::where('id', $profile->price_list_id)->where('is_active', true)->first();
                if ($priceList) {
                    return $priceList;
                }
            }
        }

        return PriceList::where('is_tenant_default', true)->where('is_active', true)->first();
    }

    /**
     * Resolve unit price for an item on a price list.
     */
    public function resolveUnitPrice(?int $priceListId, ?int $productId, ?string $description = null): ?float
    {
        if (! $priceListId) {
            return null;
        }

        $query = PriceListLine::where('price_list_id', $priceListId);

        if ($productId) {
            $line = (clone $query)->where('product_id', $productId)->first();
            if ($line) {
                return (float) $line->unit_price;
            }
        }

        if ($description) {
            $line = (clone $query)->where('description', $description)->first();
            if ($line) {
                return (float) $line->unit_price;
            }
        }

        return null;
    }

    /**
     * Validate and apply a promo code.
     */
    public function applyPromoCode(string $code, float $subtotal, ?string $date = null): array
    {
        $promo = PromoCode::where('code', strtoupper(trim($code)))->first();

        if (! $promo || ! $promo->isValid($date)) {
            throw ValidationException::withMessages([
                'promo_code' => ['The promotional code is invalid, expired, or has reached its usage limit.'],
            ]);
        }

        $discount = 0.0;
        if ($promo->discount_type === PromoCode::TYPE_PERCENTAGE) {
            $discount = round(($subtotal * (float) $promo->discount_value) / 100, 2);
        } else {
            $discount = min($subtotal, (float) $promo->discount_value);
        }

        return [
            'promo_id' => $promo->id,
            'code' => $promo->code,
            'discount_type' => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'discount_amount' => $discount,
        ];
    }

    public function recordPromoUsage(int $promoId): void
    {
        $promo = PromoCode::find($promoId);
        if ($promo) {
            $promo->increment('usage_count');
        }
    }
}
