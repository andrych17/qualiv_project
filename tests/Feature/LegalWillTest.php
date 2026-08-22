<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Models\Will;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\ProtocolBookService;
use App\Modules\Legal\Services\WillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalWillTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_will_lifecycle_and_dpw_overdue_flag(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $notaryId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            app(ProtocolBookService::class)->open([
                'book_type' => ProtocolBook::TYPE_DAFTAR_WASIAT,
                'year' => (int) now()->format('Y'),
                'notary_user_id' => $notaryId,
            ]);

            $deedType = DeedType::query()->create([
                'code' => 'wasiat', 'name' => 'Wasiat (Will)',
                'category' => DeedType::CATEGORY_NOTARY,
                'default_protocol_book_type' => ProtocolBook::TYPE_DAFTAR_WASIAT,
                'is_active' => true,
            ]);
            $testator = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Testator', 'source' => 'manual']);

            $deedService = app(DeedService::class);
            $deed = $deedService->create(['deed_type_id' => $deedType->id]);
            $deed->update(['signing_date' => now()->subDays(30)->toDateString()]);
            $deed = $deedService->transition($deed, Deed::STATUS_READY_FOR_SIGNING);
            $deed = $deedService->transition($deed, Deed::STATUS_SIGNED);
            $this->assertStringContainsString('/DW/', $deed->deed_number);

            $willService = app(WillService::class);
            $will = $willService->create($deed, $testator->id);
            $this->assertSame(Will::STATUS_DRAFTED, $will->status);

            // Signed 30 days ago, default grace is 14 days (seeded) — should be overdue.
            $this->assertTrue($will->isOverdueForDpw(14));

            $will = $willService->registerDpw($will, 'DPW-001');
            $this->assertSame(Will::STATUS_DPW_REGISTERED, $will->status);
            $this->assertFalse($will->fresh()->isOverdueForDpw(14));

            $will = $willService->activate($will);
            $this->assertSame(Will::STATUS_ACTIVE, $will->status);

            $will = $willService->open($will, 'Executed per probate order 2026/001');
            $this->assertSame(Will::STATUS_OPENED, $will->status);

            $this->expectException(RuntimeException::class);
            $willService->revoke($will, 'Cannot revoke an opened will');
        });
    }
}
