<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesPortalToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalService
{
    /**
     * Generate a signed access token for a customer.
     */
    public function generateToken(int $partnerId, ?int $expiryDays = 30): SalesPortalToken
    {
        $partner = Partner::findOrFail($partnerId);

        return SalesPortalToken::create([
            'token' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'expires_at' => $expiryDays ? now()->addDays($expiryDays) : null,
        ]);
    }

    /**
     * Authenticate and resolve customer by portal token.
     */
    public function resolveToken(string $token): array
    {
        $portalToken = SalesPortalToken::with('partner')->where('token', $token)->first();

        if (! $portalToken || ! $portalToken->isValid()) {
            throw ValidationException::withMessages([
                'token' => ['Invalid, expired, or revoked customer portal link.'],
            ]);
        }

        $customer = $portalToken->partner;

        // Fetch customer quotes
        $quotes = Quotation::with('lines')
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Quotation::STATUS_SENT, Quotation::STATUS_APPROVED, Quotation::STATUS_ACCEPTED, Quotation::STATUS_CONVERTED])
            ->orderByDesc('created_at')
            ->get();

        // Fetch customer orders
        $orders = SalesOrder::with(['lines', 'deliveries'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        // Fetch customer deliveries
        $deliveries = Delivery::whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['lines.salesOrderLine', 'order'])
            ->orderByDesc('created_at')
            ->get();

        // Fetch customer invoices from Accounting if table exists
        $invoices = [];
        if (Schema::hasTable('ACCOUNTING.ar_invoices')) {
            $invoices = ArInvoice::with('lines')
                ->where('partner_id', $customer->id)
                ->where('status', ArInvoice::STATUS_POSTED)
                ->orderByDesc('issue_date')
                ->get();
        }

        return [
            'token' => $portalToken,
            'customer' => $customer,
            'quotes' => $quotes,
            'orders' => $orders,
            'deliveries' => $deliveries,
            'invoices' => $invoices,
        ];
    }
}
