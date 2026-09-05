<?php

namespace Tests\Feature\DMS;

use App\Modules\DMS\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A Main Dashboard — summary cards + tabbed recent-activity preview (Recent Uploads | Expiring Soon | On Legal Hold). */
class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_dashboard_reports_summary_and_tabs(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $this->makeDocument(['title' => 'Recent']);
            $this->makeDocument(['title' => 'Expiring', 'expiry_date' => now()->addDays(5)->toDateString()]);
            $this->makeDocument(['title' => 'On Hold', 'legal_hold' => true]);
            $this->makeDocument(['title' => 'Purged', 'status' => Document::STATUS_PURGED]);
        });

        $this->get('/dms/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Dashboard/Index')
                ->where('summary.total_documents', 4)
                ->where('summary.active_documents', 3)
                ->where('summary.expiring_soon', 1)
                ->where('summary.on_legal_hold', 1)
                ->has('recentUploads', 3)
                ->has('expiringSoon', 1)
                ->has('onLegalHold', 1));
    }

    public function test_dashboard_with_no_documents(): void
    {
        $this->loginAsDmsAdmin();

        $this->get('/dms/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.total_documents', 0)
                ->has('recentUploads', 0)->has('expiringSoon', 0)->has('onLegalHold', 0));
    }

    public function test_dashboard_redirect_from_dms_root(): void
    {
        $this->loginAsDmsAdmin();

        $this->get('/dms')->assertRedirect('/dms/dashboard');
    }
}
