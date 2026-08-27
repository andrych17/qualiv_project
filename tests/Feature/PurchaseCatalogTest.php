<?php

namespace Tests\Feature;

use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseCatalogTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_view_catalog_index_and_create_page(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/purchase/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Catalog/Index'));

        $this->get('/purchase/catalog/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Catalog/Create'));
    }

    public function test_admin_can_create_update_and_toggle_catalog_item(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $partnerId = null;
        $categoryId = null;

        $tenant->run(function () use (&$partnerId, &$categoryId) {
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Tech Supplier', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);
            $partnerId = $partner->id;

            $cat = Category::create(['name' => 'IT Equipment', 'kind' => 'direct', 'capex_opex' => 'capex', 'is_active' => true]);
            $categoryId = $cat->id;
        });

        // Store Catalog Item
        $response = $this->post('/purchase/catalog', [
            'item_code' => 'IT-LAPTOP-01',
            'description' => 'Dell Latitude 5440 i7 16GB',
            'category_id' => $categoryId,
            'unit' => 'unit',
            'preferred_supplier_id' => $partnerId,
            'negotiated_price' => 18500000,
            'price_valid_from' => now()->toDateString(),
            'price_valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        $response->assertRedirect('/purchase/catalog');

        $itemId = null;

        $tenant->run(function () use (&$itemId) {
            $item = PurCatalogItem::where('item_code', 'IT-LAPTOP-01')->first();
            $this->assertNotNull($item);
            $this->assertEquals(18500000, (float) $item->negotiated_price);
            $this->assertTrue($item->is_active);

            $itemId = $item->id;
        });

        // Update Catalog Item
        $this->put("/purchase/catalog/{$itemId}", [
            'item_code' => 'IT-LAPTOP-01',
            'description' => 'Dell Latitude 5440 i7 32GB (Upgraded)',
            'category_id' => $categoryId,
            'unit' => 'unit',
            'preferred_supplier_id' => $partnerId,
            'negotiated_price' => 20000000,
            'price_valid_from' => now()->toDateString(),
            'price_valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
        ])->assertRedirect('/purchase/catalog');

        $tenant->run(function () use ($itemId) {
            $item = PurCatalogItem::find($itemId);
            $this->assertSame('Dell Latitude 5440 i7 32GB (Upgraded)', $item->description);
            $this->assertEquals(20000000, (float) $item->negotiated_price);
        });

        // Toggle Active State
        $this->post("/purchase/catalog/{$itemId}/toggle")
            ->assertRedirect();

        $tenant->run(function () use ($itemId) {
            $item = PurCatalogItem::find($itemId);
            $this->assertFalse($item->is_active);
        });
    }
}
