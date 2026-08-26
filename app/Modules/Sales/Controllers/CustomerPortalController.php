<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\PortalService;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function __construct(protected PortalService $portalService) {}

    public function show(string $token): Response
    {
        $data = $this->portalService->resolveToken($token);

        return Inertia::render('Sales/Portal/Show', [
            'token' => $data['token'],
            'customer' => $data['customer'],
            'quotes' => $data['quotes'],
            'orders' => $data['orders'],
            'deliveries' => $data['deliveries'],
            'invoices' => $data['invoices'],
        ]);
    }
}
