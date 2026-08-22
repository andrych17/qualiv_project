<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Models\ProtocolEntry;
use RuntimeException;

class ProtocolBookService
{
    /** @param  array<string, mixed>  $data */
    public function open(array $data): ProtocolBook
    {
        return ProtocolBook::query()->create([
            'book_type' => $data['book_type'],
            'year' => $data['year'],
            'volume' => $data['volume'] ?? 1,
            'status' => ProtocolBook::STATUS_ACTIVE,
            'notary_user_id' => $data['notary_user_id'],
            'opened_at' => $data['opened_at'] ?? now()->toDateString(),
        ]);
    }

    public function close(ProtocolBook $book): ProtocolBook
    {
        if ($book->status !== ProtocolBook::STATUS_ACTIVE) {
            throw new RuntimeException('Only an active book can be closed.');
        }

        $book->update(['status' => ProtocolBook::STATUS_CLOSED, 'closed_at' => now()->toDateString()]);

        return $book->refresh();
    }

    /** Year-close, retirement, or transfer (§3F) — a real professional-conduct requirement, logged not silent. */
    public function handover(ProtocolBook $book, string $recipient): ProtocolBook
    {
        if ($book->status === ProtocolBook::STATUS_HANDED_OVER) {
            throw new RuntimeException('This book has already been handed over.');
        }

        $book->update([
            'status' => ProtocolBook::STATUS_HANDED_OVER,
            'handed_over_to' => $recipient,
            'handed_over_at' => now()->toDateString(),
        ]);

        return $book->refresh();
    }

    /**
     * Assigns the next gap-free sequence number for an active book and appends the ledger
     * entry — must run inside the caller's signing transaction (§5 protocol ledger integrity).
     * The row lock on `book` prevents two deeds signing concurrently from racing the same
     * sequence number.
     */
    public function recordEntry(ProtocolBook $book, ?Deed $deed, string $entryDate): ProtocolEntry
    {
        $locked = ProtocolBook::query()->lockForUpdate()->findOrFail($book->id);

        if ($locked->status !== ProtocolBook::STATUS_ACTIVE) {
            throw new RuntimeException("Protocol book #{$locked->id} is not active — cannot assign a sequence number.");
        }

        $next = (int) ProtocolEntry::query()->where('book_id', $locked->id)->max('sequence_number') + 1;

        return ProtocolEntry::query()->create([
            'book_id' => $locked->id,
            'deed_id' => $deed?->id,
            'sequence_number' => $next,
            'entry_date' => $entryDate,
            'created_at' => now(),
        ]);
    }

    /** Active repertorium-family book for the given type + year — signing needs exactly this. */
    public function findActiveBook(string $bookType, int $year): ?ProtocolBook
    {
        return ProtocolBook::query()
            ->where('book_type', $bookType)
            ->where('year', $year)
            ->where('status', ProtocolBook::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
    }
}
