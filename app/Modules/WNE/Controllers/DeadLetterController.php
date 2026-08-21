<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Services\RetryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3M — the DLQ tab §3A's Dashboard doesn't exist yet to host: list + resend + discard. */
class DeadLetterController extends Controller
{
    public function __construct(private readonly RetryService $retry) {}

    public function index(): Response
    {
        $rows = MsgDeadLetter::query()
            ->with('recipient')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MsgDeadLetter $dl) => [
                'id' => $dl->id,
                'channel' => $dl->channel,
                'subject' => $dl->subject,
                'recipient_email' => $dl->recipient?->email,
                'attempts' => count($dl->failure_history),
                'last_error' => collect($dl->failure_history)->last()['error'] ?? null,
                'created_at' => $dl->created_at->format('Y-m-d H:i'),
                'resent_at' => $dl->resent_at?->format('Y-m-d H:i'),
                'discarded_at' => $dl->discarded_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('WNE/DeadLetters/Index', ['deadLetters' => $rows]);
    }

    public function resend(Request $request, MsgDeadLetter $deadLetter): RedirectResponse
    {
        $this->retry->resend($deadLetter, $request->user()->id);

        return back()->with('success', 'Delivery re-queued with a fresh attempt counter.');
    }

    public function discard(Request $request, MsgDeadLetter $deadLetter): RedirectResponse
    {
        $this->retry->discard($deadLetter, $request->user()->id);

        return back()->with('success', 'Dead letter discarded.');
    }
}
