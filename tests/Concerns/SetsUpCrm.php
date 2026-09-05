<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\CRM\Models\Industry;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\CRM\Models\TicketCategory;

/** Shared bootstrap for CRM module tests — plan activation, admin login, and master-data fixtures. */
trait SetsUpCrm
{
    protected function loginAsCrmAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeIndustry(string $name = 'Technology'): Industry
    {
        return Industry::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    protected function makeRoleType(string $code = 'CUSTOMER', string $name = 'Customer'): PartnerRoleType
    {
        return PartnerRoleType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }

    protected function makeLeadSource(string $name = 'Website'): LeadSource
    {
        return LeadSource::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    protected function makeTicketCategory(string $name = 'General'): TicketCategory
    {
        return TicketCategory::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    protected function makeCompany(string $name = 'Acme Corp'): Partner
    {
        return Partner::query()->create(['type' => Partner::TYPE_ORGANIZATION, 'name' => $name]);
    }

    protected function makeContact(string $name = 'Jane Doe', ?int $parentPartnerId = null): Partner
    {
        return Partner::query()->create([
            'type' => Partner::TYPE_INDIVIDUAL, 'name' => $name, 'parent_partner_id' => $parentPartnerId,
        ]);
    }
}
