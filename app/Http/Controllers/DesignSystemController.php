<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

// ponytail: Single-action controller to render Design System component showcase page
class DesignSystemController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('DesignSystem/Index');
    }
}
