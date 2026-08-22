<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3E rides entirely on §3C's Deed/DeedService machinery — legalisasi/waarmerking are just
 * two more notary-category deed_types, each routed to its own protocol book. This test
 * proves that config-only reuse actually numbers into the right book, not shared assertions
 * already covered by LegalDeedCrudTest/LegalProtocolBookTest.
 */
class LegalLegalisasiWaarmerkingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_legalisasi_and_waarmerking_number_into_their_own_books(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $protocol = app(ProtocolBookService::class);
            $year = (int) now()->format('Y');

            $protocol->open(['book_type' => ProtocolBook::TYPE_LEGALISASI, 'year' => $year, 'notary_user_id' => $notaryId]);
            $protocol->open(['book_type' => ProtocolBook::TYPE_WAARMERKING, 'year' => $year, 'notary_user_id' => $notaryId]);

            $legalisasiType = DeedType::query()->create([
                'code' => 'legalisasi', 'name' => 'Legalisasi', 'category' => DeedType::CATEGORY_NOTARY,
                'default_protocol_book_type' => ProtocolBook::TYPE_LEGALISASI, 'is_active' => true,
            ]);
            $waarmerkingType = DeedType::query()->create([
                'code' => 'waarmerking', 'name' => 'Waarmerking', 'category' => DeedType::CATEGORY_NOTARY,
                'default_protocol_book_type' => ProtocolBook::TYPE_WAARMERKING, 'is_active' => true,
            ]);

            $deedService = app(DeedService::class);

            $legalisasi = $deedService->create(['deed_type_id' => $legalisasiType->id]);
            $legalisasi->update(['signing_date' => now()->toDateString()]);
            $legalisasi = $deedService->transition($legalisasi, Deed::STATUS_READY_FOR_SIGNING);
            $legalisasi = $deedService->transition($legalisasi, Deed::STATUS_SIGNED);

            $waarmerking = $deedService->create(['deed_type_id' => $waarmerkingType->id]);
            $waarmerking->update(['signing_date' => now()->toDateString()]);
            $waarmerking = $deedService->transition($waarmerking, Deed::STATUS_READY_FOR_SIGNING);
            $waarmerking = $deedService->transition($waarmerking, Deed::STATUS_SIGNED);

            $this->assertStringContainsString('/Leg/', $legalisasi->deed_number);
            $this->assertStringContainsString('/Wmk/', $waarmerking->deed_number);
        });
    }
}
