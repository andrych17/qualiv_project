<?php

namespace Tests\Feature\POS;

use App\Modules\POS\Models\PosProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PosMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_pos_migrations_and_seeds_run_cleanly(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            // Verify schema exists
            $schemaExists = DB::selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'POS'");
            $this->assertNotNull($schemaExists);

            // Verify default profiles seeded
            $convenience = DB::table('POS.pos_profiles')->where('code', 'CONVENIENCE')->first();
            $this->assertNotNull($convenience);
            $this->assertEquals('retail', $convenience->base_type);

            $restaurant = DB::table('POS.pos_profiles')->where('code', 'RESTAURANT')->first();
            $this->assertNotNull($restaurant);
            $this->assertEquals('restaurant', $restaurant->base_type);
            $this->assertTrue((bool) $restaurant->table_management);
            $this->assertTrue((bool) $restaurant->kds_enabled);

            // Verify menu seeded
            $menu = DB::table('SYSCONFIG.config_menus')->where('code', 'POS')->first();
            $this->assertNotNull($menu);

            // Verify const seeded
            $const = DB::table('SYSCONFIG.config_consts')->where('group_code', 'POS_ALLOW_OVERSELL')->first();
            $this->assertNotNull($const);
            $this->assertEquals('Y', $const->value);
        });
    }
}
