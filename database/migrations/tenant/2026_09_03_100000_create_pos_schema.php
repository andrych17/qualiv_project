<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS module schema — created here (not only in CreateModuleSchemas) so tenants
 * provisioned before this module existed pick it up via `tenants:migrate`.
 * Also widens INVENTORY.product_barcodes type check to add 'plu' (§3E).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "POS"');

        $role = DB::connection()->getConfig('username');
        if ($role) {
            DB::statement('GRANT ALL ON SCHEMA "POS" TO "'.$role.'"');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA "POS" GRANT ALL ON TABLES TO "'.$role.'"');
        }

        // §3E / POS_SPECS.sql step 0: Widen product_barcodes type check to allow 'plu'
        DB::statement('ALTER TABLE "INVENTORY".product_barcodes DROP CONSTRAINT IF EXISTS product_barcodes_type_check');
        DB::statement("ALTER TABLE \"INVENTORY\".product_barcodes ADD CONSTRAINT product_barcodes_type_check CHECK (type IN ('primary', 'case_pack', 'alternate', 'plu'))");
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "POS" CASCADE');
    }
};
