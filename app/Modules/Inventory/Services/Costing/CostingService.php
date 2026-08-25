<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Modules\Inventory\Models\Product;

class CostingService
{
    public function strategyFor(Product $product): CostingStrategyInterface
    {
        return match ($product->costing_method) {
            Product::COSTING_AVERAGE => app(AverageStrategy::class),
            default => app(FifoStrategy::class),
        };
    }
}
