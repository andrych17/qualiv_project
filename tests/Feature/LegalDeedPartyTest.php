<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\PartyRoleType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedPartyService;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\ProtocolBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalDeedPartyTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_party_can_be_added_via_existing_partner_or_quick_add(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'akta_party_test', 'name' => 'Akta Party Test',
                'category' => DeedType::CATEGORY_NOTARY, 'is_active' => true,
            ]);
            $roleType = PartyRoleType::query()->create(['code' => 'penghadap', 'name' => 'Penghadap', 'is_active' => true]);
            $existingPartner = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Budi', 'source' => 'manual']);

            $deed = app(DeedService::class)->create(['deed_type_id' => $deedType->id]);
            $partyService = app(DeedPartyService::class);

            $viaExisting = $partyService->add($deed, [
                'partner_id' => $existingPartner->id,
                'role_type_id' => $roleType->id,
                'identity_name' => 'Budi Santoso',
                'identity_id_number' => '3201xxxx',
            ]);
            $this->assertSame($existingPartner->id, $viaExisting->partner_id);
            $this->assertSame('Budi Santoso', $viaExisting->identity_snapshot['name']);

            $quickAdded = $partyService->add($deed, [
                'role_type_id' => $roleType->id,
                'identity_name' => 'Walk-in Witness',
            ]);
            $this->assertNotNull($quickAdded->partner_id);
            $newPartner = Partner::query()->findOrFail($quickAdded->partner_id);
            $this->assertSame('Walk-in Witness', $newPartner->name);
            $this->assertSame('legal_quick_add', $newPartner->source);

            $this->assertCount(2, $deed->parties()->get());
        });
    }

    public function test_signed_deed_rejects_party_changes(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $deedType = DeedType::query()->create([
                'code' => 'akta_party_test2', 'name' => 'Akta Party Test 2',
                'category' => DeedType::CATEGORY_NOTARY, 'is_active' => true,
            ]);
            $roleType = PartyRoleType::query()->create(['code' => 'saksi', 'name' => 'Saksi', 'is_active' => true]);

            app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => User::query()->where('email', 'admin@nusaevo.com')->value('id'),
            ]);

            $deedService = app(DeedService::class);
            $partyService = app(DeedPartyService::class);

            $deed = $deedService->create(['deed_type_id' => $deedType->id]);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = $deedService->transition($deed, Deed::STATUS_READY_FOR_SIGNING);
            $deed = $deedService->transition($deed, Deed::STATUS_SIGNED);

            $this->expectException(RuntimeException::class);
            $partyService->add($deed, ['role_type_id' => $roleType->id, 'identity_name' => 'Too Late']);
        });
    }
}
