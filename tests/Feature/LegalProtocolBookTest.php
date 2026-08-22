<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Models\ProtocolEntry;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalProtocolBookTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_signing_without_an_active_book_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'akta_no_book', 'name' => 'Akta No Book',
                'category' => DeedType::CATEGORY_NOTARY, 'is_active' => true,
            ]);

            $service = app(DeedService::class);
            $deed = $service->create(['deed_type_id' => $deedType->id]);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $service->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            $this->expectException(RuntimeException::class);
            $service->transition($deed, Deed::STATUS_SIGNED);
        });
    }

    public function test_sequence_numbers_are_gap_free_per_book(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'akta_seq', 'name' => 'Akta Seq',
                'category' => DeedType::CATEGORY_NOTARY, 'is_active' => true,
            ]);

            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $book = app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => $notaryId,
            ]);

            $deedService = app(DeedService::class);

            $first = $deedService->create(['deed_type_id' => $deedType->id]);
            $first->update(['signing_date' => now()->toDateString()]);
            $first = $deedService->transition($first, Deed::STATUS_READY_FOR_SIGNING);
            $first = $deedService->transition($first, Deed::STATUS_SIGNED);

            $second = $deedService->create(['deed_type_id' => $deedType->id]);
            $second->update(['signing_date' => now()->toDateString()]);
            $second = $deedService->transition($second, Deed::STATUS_READY_FOR_SIGNING);
            $second = $deedService->transition($second, Deed::STATUS_SIGNED);

            $this->assertSame("1/Rep/{$book->year}", $first->deed_number);
            $this->assertSame("2/Rep/{$book->year}", $second->deed_number);
            $this->assertSame(2, ProtocolEntry::query()->where('book_id', $book->id)->count());
        });
    }

    public function test_protocol_entries_are_append_only(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $book = app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => $notaryId,
            ]);

            $entry = app(ProtocolBookService::class)->recordEntry($book, null, now()->toDateString());

            $this->expectException(LogicException::class);
            $entry->delete();
        });
    }

    public function test_book_close_and_handover_lifecycle(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $service = app(ProtocolBookService::class);
            $book = $service->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => $notaryId,
            ]);

            $book = $service->close($book);
            $this->assertSame(ProtocolBook::STATUS_CLOSED, $book->status);

            $book = $service->handover($book, 'Notaris Pengganti Jane Doe');
            $this->assertSame(ProtocolBook::STATUS_HANDED_OVER, $book->status);
            $this->assertSame('Notaris Pengganti Jane Doe', $book->handed_over_to);

            $this->expectException(RuntimeException::class);
            $service->handover($book, 'Someone else');
        });
    }
}
