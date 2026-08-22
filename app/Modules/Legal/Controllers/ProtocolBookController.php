<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Requests\HandoverProtocolBookRequest;
use App\Modules\Legal\Requests\StoreProtocolBookRequest;
use App\Modules\Legal\Services\ProtocolBookService;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolBookController extends Controller
{
    public function __construct(
        protected ProtocolBookService $service,
    ) {}

    public function index(): Response
    {
        $books = ProtocolBook::query()
            ->withCount('entries')
            ->with('notary:id,name')
            ->orderByDesc('year')
            ->orderBy('book_type')
            ->get()
            ->map(fn (ProtocolBook $b) => [
                'id' => $b->id,
                'book_type' => $b->book_type,
                'year' => $b->year,
                'volume' => $b->volume,
                'status' => $b->status,
                'notary_name' => $b->notary?->name,
                'entries_count' => $b->entries_count,
                'opened_at' => $b->opened_at?->toDateString(),
                'closed_at' => $b->closed_at?->toDateString(),
                'handed_over_to' => $b->handed_over_to,
            ]);

        return Inertia::render('Legal/ProtocolBooks/Index', ['books' => $books]);
    }

    public function create(): Response
    {
        return Inertia::render('Legal/ProtocolBooks/Create', [
            'bookTypes' => ProtocolBook::TYPES,
            'notaries' => User::query()->orderBy('name')->get(['id', 'name']),
            'currentYear' => (int) now()->format('Y'),
        ]);
    }

    public function store(StoreProtocolBookRequest $request)
    {
        $book = $this->service->open($request->validated());

        return redirect()->route('legal.protocolBooks.show', $book)
            ->with('success', 'Protocol book opened.');
    }

    public function show(ProtocolBook $protocolBook): Response
    {
        return Inertia::render('Legal/ProtocolBooks/Show', [
            'book' => [
                'id' => $protocolBook->id,
                'book_type' => $protocolBook->book_type,
                'year' => $protocolBook->year,
                'volume' => $protocolBook->volume,
                'status' => $protocolBook->status,
                'notary_name' => $protocolBook->notary?->name,
                'opened_at' => $protocolBook->opened_at?->toDateString(),
                'closed_at' => $protocolBook->closed_at?->toDateString(),
                'handed_over_to' => $protocolBook->handed_over_to,
                'handed_over_at' => $protocolBook->handed_over_at?->toDateString(),
            ],
            'entries' => $protocolBook->entries()->with('deed:id,deed_number,minuta_reference')->get()->map(fn ($e) => [
                'id' => $e->id,
                'sequence_number' => $e->sequence_number,
                'entry_date' => $e->entry_date->toDateString(),
                'deed_number' => $e->deed?->deed_number,
                'deed_id' => $e->deed_id,
            ]),
        ]);
    }

    public function close(ProtocolBook $protocolBook)
    {
        $this->service->close($protocolBook);

        return redirect()->route('legal.protocolBooks.show', $protocolBook)
            ->with('success', 'Protocol book closed.');
    }

    public function handover(HandoverProtocolBookRequest $request, ProtocolBook $protocolBook)
    {
        $this->service->handover($protocolBook, $request->validated()['recipient']);

        return redirect()->route('legal.protocolBooks.show', $protocolBook)
            ->with('success', 'Protocol book handed over.');
    }

    public function manifest(ProtocolBook $protocolBook): Response
    {
        return Inertia::render('Legal/ProtocolBooks/Manifest', [
            'book' => [
                'book_type' => $protocolBook->book_type,
                'year' => $protocolBook->year,
                'volume' => $protocolBook->volume,
                'status' => $protocolBook->status,
                'notary_name' => $protocolBook->notary?->name,
                'opened_at' => $protocolBook->opened_at?->toDateString(),
                'closed_at' => $protocolBook->closed_at?->toDateString(),
                'handed_over_to' => $protocolBook->handed_over_to,
                'handed_over_at' => $protocolBook->handed_over_at?->toDateString(),
            ],
            'entries' => $protocolBook->entries()->with('deed:id,deed_number,minuta_reference')->get()->map(fn ($e) => [
                'sequence_number' => $e->sequence_number,
                'entry_date' => $e->entry_date->toDateString(),
                'deed_number' => $e->deed?->deed_number,
                'minuta_reference' => $e->deed?->minuta_reference,
            ]),
        ]);
    }
}
