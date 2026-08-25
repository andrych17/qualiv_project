<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\BarcodeResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §3K — one resolve endpoint shared by every "scan a barcode" input across the module. */
class BarcodeScanController extends Controller
{
    public function __construct(protected BarcodeResolverService $resolver) {}

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            'context' => 'required|in:product,location',
            'warehouse_id' => 'nullable|integer',
        ]);

        $result = $data['context'] === 'product'
            ? $this->resolver->resolveProduct($data['code'])
            : $this->resolver->resolveLocation($data['code'], $data['warehouse_id'] ?? null);

        if (! $result) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true, ...$result]);
    }
}
