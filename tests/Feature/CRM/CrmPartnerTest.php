<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Services\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B/§3C — Contacts and Companies both back onto the unified Partner model/PartnerService. */
class CrmPartnerTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    public function test_admin_can_crud_a_company_with_addresses_contact_points_and_roles(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $industryId = null;
        $roleTypeId = null;
        $tenant->run(function () use (&$industryId, &$roleTypeId) {
            $industryId = $this->makeIndustry()->id;
            $roleTypeId = $this->makeRoleType('CUSTOMER', 'Customer')->id;
        });

        $this->get('/crm/companies')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Companies/Index'));
        $this->get('/crm/companies/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('CRM/Companies/Create')
            ->has('industries', 1)
            ->has('roleTypes', 1));

        $this->post('/crm/companies', [
            'name' => 'Acme Corp',
            'trade_name' => 'Acme',
            'registration_tax_id' => '12.345.678.9-012.000',
            'industry_id' => $industryId,
            'tags' => 'vip, wholesale, ,',
            'role_type_ids' => [$roleTypeId],
            'addresses' => [
                ['type' => 'billing', 'line1' => '1 Main St', 'city' => 'Jakarta', 'is_primary' => true],
                ['line1' => ''], // empty line1 -> skipped by PartnerService::syncAddresses
            ],
            'contact_points' => [
                ['type' => 'email', 'value' => 'hello@acme.test', 'is_primary' => true],
                ['type' => 'phone', 'value' => ''], // empty value -> skipped
            ],
        ])->assertRedirect(route('crm.companies.index'));

        $companyId = null;
        $tenant->run(function () use (&$companyId, $roleTypeId) {
            $company = Partner::query()->where('name', 'Acme Corp')->first();
            $this->assertNotNull($company);
            $this->assertSame(Partner::TYPE_ORGANIZATION, $company->type);
            $this->assertSame(['vip', 'wholesale'], $company->tags);
            $this->assertSame(1, Address::query()->where('partner_id', $company->id)->count());
            $this->assertSame(1, ContactPoint::query()->where('partner_id', $company->id)->count());
            $this->assertSame(1, PartnerRole::query()->where('partner_id', $company->id)->where('role_type_id', $roleTypeId)->where('is_active', true)->count());
            $companyId = $company->id;
        });

        $this->get("/crm/companies/{$companyId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CRM/Companies/Edit')
                ->where('company.name', 'Acme Corp')
                ->where('company.tags', 'vip, wholesale')
                ->has('company.addresses', 1)
                ->has('company.role_type_ids', 1));

        $newRoleTypeId = null;
        $tenant->run(function () use (&$newRoleTypeId) {
            $newRoleTypeId = $this->makeRoleType('PARTNER', 'Channel Partner')->id;
        });

        // Update swaps the role set entirely: old role deactivated (kept for history), new one created.
        $this->put("/crm/companies/{$companyId}", [
            'name' => 'Acme Corp (Updated)',
            'is_active' => true,
            'role_type_ids' => [$newRoleTypeId],
        ])->assertRedirect(route('crm.companies.index'));

        $tenant->run(function () use ($companyId, $roleTypeId, $newRoleTypeId) {
            $this->assertSame('Acme Corp (Updated)', Partner::query()->find($companyId)->name);
            $this->assertFalse((bool) PartnerRole::query()->where('partner_id', $companyId)->where('role_type_id', $roleTypeId)->value('is_active'));
            $this->assertTrue((bool) PartnerRole::query()->where('partner_id', $companyId)->where('role_type_id', $newRoleTypeId)->value('is_active'));
            // Addresses/contact points passed as empty arrays on update -> cleared.
            $this->assertSame(0, Address::query()->where('partner_id', $companyId)->count());
        });

        $this->delete("/crm/companies/{$companyId}")->assertRedirect(route('crm.companies.index'));
        $tenant->run(function () use ($companyId) {
            $company = Partner::query()->find($companyId);
            $this->assertNotNull($company);
            $this->assertFalse($company->is_active);
        });
    }

    public function test_admin_can_crud_a_contact_linked_to_a_parent_company(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get('/crm/contacts')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Contacts/Index'));
        $this->get('/crm/contacts/create')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Contacts/Create'));

        $this->post('/crm/contacts', [
            'name' => 'Jane Doe',
            'title_position' => 'Purchasing Manager',
            'parent_partner_id' => $companyId,
        ])->assertRedirect(route('crm.contacts.index'));

        $contactId = null;
        $tenant->run(function () use (&$contactId, $companyId) {
            $contact = Partner::query()->where('name', 'Jane Doe')->first();
            $this->assertSame(Partner::TYPE_INDIVIDUAL, $contact->type);
            $this->assertSame($companyId, $contact->parent_partner_id);
            $contactId = $contact->id;
        });

        $this->get("/crm/contacts/{$contactId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CRM/Contacts/Edit')
                ->where('contact.parent.label', 'Acme Corp'));

        $this->put("/crm/contacts/{$contactId}", [
            'name' => 'Jane Doe-Smith',
            'title_position' => 'VP Purchasing',
            'is_active' => true,
        ])->assertRedirect(route('crm.contacts.index'));

        $tenant->run(function () use ($contactId) {
            $this->assertSame('Jane Doe-Smith', Partner::query()->find($contactId)->name);
        });

        $this->delete("/crm/contacts/{$contactId}")->assertRedirect(route('crm.contacts.index'));
        $tenant->run(function () use ($contactId) {
            $this->assertFalse((bool) Partner::query()->find($contactId)->is_active);
        });
    }

    public function test_partner_index_filters_sort_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $companyIds = [];
        $tenant->run(function () use (&$companyIds) {
            $companyIds[] = $this->makeCompany('Alpha Inc')->id;
            $companyIds[] = $this->makeCompany('Beta LLC')->id;
            Partner::query()->find($companyIds[1])->update(['is_active' => false]);
        });

        $this->get('/crm/companies?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Alpha Inc'));

        $this->get('/crm/companies?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Beta LLC'));

        $this->get('/crm/companies?sort=name&direction=asc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('companies.data.0.name', 'Alpha Inc'));

        $this->delete('/crm/companies/bulk-destroy', ['ids' => $companyIds])->assertRedirect();
        $tenant->run(function () use ($companyIds) {
            foreach ($companyIds as $id) {
                $this->assertFalse((bool) Partner::query()->find($id)->is_active);
            }
        });

        $this->delete('/crm/companies/bulk-destroy', ['ids' => []])->assertSessionHasErrors(['ids']);

        $contactId = null;
        $tenant->run(function () use (&$contactId) {
            $contactId = $this->makeContact()->id;
        });
        $this->delete('/crm/contacts/bulk-destroy', ['ids' => [$contactId]])->assertRedirect();
        $tenant->run(function () use ($contactId) {
            $this->assertFalse((bool) Partner::query()->find($contactId)->is_active);
        });
    }

    public function test_store_and_update_company_validation_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/companies', [])->assertSessionHasErrors(['name']);

        $this->post('/crm/companies', [
            'name' => 'Bad Refs Co',
            'industry_id' => 999999,
            'parent_partner_id' => 999999,
            'role_type_ids' => [999999],
        ])->assertSessionHasErrors(['industry_id', 'parent_partner_id', 'role_type_ids']);

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany('Self Parent Co')->id;
        });

        $this->put("/crm/companies/{$companyId}", [
            'name' => 'Self Parent Co',
            'parent_partner_id' => $companyId,
        ])->assertSessionHasErrors(['parent_partner_id']);

        $this->put("/crm/companies/{$companyId}", [
            'name' => 'Self Parent Co',
            'industry_id' => 999999,
            'role_type_ids' => [999999],
        ])->assertSessionHasErrors(['industry_id', 'role_type_ids']);

        // A nonexistent parent that isn't the record itself hits the elseif branch, not the self-parent one.
        $this->put("/crm/companies/{$companyId}", [
            'name' => 'Self Parent Co',
            'parent_partner_id' => 999999,
        ])->assertSessionHasErrors(['parent_partner_id']);
    }

    public function test_store_and_update_contact_validation_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/contacts', [])->assertSessionHasErrors(['name']);

        $this->post('/crm/contacts', [
            'name' => 'Bad Refs Contact',
            'parent_partner_id' => 999999,
            'role_type_ids' => [999999],
        ])->assertSessionHasErrors(['parent_partner_id', 'role_type_ids']);

        $contactId = null;
        $tenant->run(function () use (&$contactId) {
            $contactId = $this->makeContact('Self Parent Contact')->id;
        });

        $this->put("/crm/contacts/{$contactId}", [
            'name' => 'Self Parent Contact',
            'role_type_ids' => [999999],
        ])->assertSessionHasErrors(['role_type_ids']);

        $this->put("/crm/contacts/{$contactId}", [
            'name' => 'Self Parent Contact',
            'parent_partner_id' => $contactId,
        ])->assertSessionHasErrors(['parent_partner_id']);

        // A nonexistent parent that isn't the record itself hits the elseif branch, not the self-parent one.
        $this->put("/crm/contacts/{$contactId}", [
            'name' => 'Self Parent Contact',
            'parent_partner_id' => 999999,
        ])->assertSessionHasErrors(['parent_partner_id']);
    }

    /**
     * parseTags' array branch is unreachable through the HTTP layer — the FormRequest always
     * validates `tags` as a string. Unlike the string branch, the array branch filters out
     * blank entries but does not trim the survivors — that's the actual current behavior.
     */
    public function test_partner_service_accepts_an_already_split_tags_array_when_called_directly(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $service = app(PartnerService::class);

            $partner = $service->create(['name' => 'Direct Co', 'tags' => ['a', ' b ', '', 'c']], Partner::TYPE_ORGANIZATION);

            $this->assertSame(['a', ' b ', 'c'], $partner->tags);
        });
    }
}
