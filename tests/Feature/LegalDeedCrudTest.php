<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalDeedCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_deed_lifecycle_locks_on_signing(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $deedTypeId = null;
        $tenant->run(function () use (&$deedTypeId) {
            $deedTypeId = DeedType::query()->create([
                'code' => 'akta_test',
                'name' => 'Akta Test',
                'category' => DeedType::CATEGORY_NOTARY,
                'requires_tax' => false,
                'requires_bpn_registration' => false,
                'is_active' => true,
            ])->id;
        });

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->post('/legal/deeds', [
            'deed_type_id' => $deedTypeId,
            'minuta_reference' => 'MIN-001',
        ])->assertRedirect();

        $tenant->run(function () use ($deedTypeId) {
            $deed = Deed::query()->where('deed_type_id', $deedTypeId)->firstOrFail();
            $this->assertSame(Deed::STATUS_DRAFT, $deed->status);
            $this->assertSame(DeedType::CATEGORY_NOTARY, $deed->category);

            $service = app(DeedService::class);
            $deed = $service->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            // Cannot sign without a signing date first.
            $this->expectException(RuntimeException::class);
            $service->transition($deed, Deed::STATUS_SIGNED);
        });
    }

    public function test_signed_deed_is_immutable(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'akta_test2',
                'name' => 'Akta Test 2',
                'category' => DeedType::CATEGORY_NOTARY,
                'requires_tax' => false,
                'requires_bpn_registration' => false,
                'is_active' => true,
            ]);

            app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => User::query()->where('email', 'admin@nusaevo.com')->value('id'),
            ]);

            $service = app(DeedService::class);
            $deed = $service->create(['deed_type_id' => $deedType->id]);

            $deed->update(['signing_date' => now()->toDateString()]);
            $service->transition($deed, Deed::STATUS_READY_FOR_SIGNING);
            $deed = $service->transition($deed, Deed::STATUS_SIGNED);

            $this->assertTrue($deed->isLocked());
            $this->assertNotNull($deed->deed_number);
            $this->assertStringContainsString('/Rep/', $deed->deed_number);

            $this->expectException(RuntimeException::class);
            $service->update($deed, ['deed_type_id' => $deedType->id, 'summary' => 'edited after signing']);
        });
    }
}
