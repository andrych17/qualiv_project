<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Services\ExceptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExceptionController extends Controller
{
    public function __construct(
        protected ExceptionService $service,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');
        $type = $request->query('type');

        $query = PurException::query()
            ->with(['resolver:id,name'])
            ->orderByDesc('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('exception_type', $type);
        }

        $exceptions = $query->get()->map(fn (PurException $e) => [
            'id' => $e->id,
            'exception_type' => $e->exception_type,
            'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id,
            'summary' => $e->summary,
            'status' => $e->status,
            'resolved_by' => $e->resolver?->name,
            'resolved_at' => $e->resolved_at?->toDateTimeString(),
            'created_at' => $e->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Purchase/Exceptions/Index', [
            'exceptions' => $exceptions,
            'currentStatus' => $status,
            'currentType' => $type,
        ]);
    }

    public function resolve(PurException $exception, Request $request)
    {
        $this->service->resolve($exception, $request->user()->id);

        return redirect()->back()->with('success', 'Exception marked as resolved.');
    }

    public function dismiss(PurException $exception, Request $request)
    {
        $this->service->dismiss($exception, $request->user()->id);

        return redirect()->back()->with('success', 'Exception dismissed.');
    }

    public function scan()
    {
        $late = $this->service->scanLateDeliveries();
        $overdue = $this->service->scanOverdueApprovals();

        return redirect()->back()->with('success', "Exception scan complete: {$late} late deliveries, {$overdue} overdue approvals found.");
    }
}
