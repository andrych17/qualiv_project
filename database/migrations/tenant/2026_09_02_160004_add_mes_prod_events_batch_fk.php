<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MES_SPECS.sql's own note on `mes_prod_events.batch_id`: "FK added after mes_batches exists,
 * below". `mes_batches` now exists (§3I, 2026_09_02_160002_create_mes_batch_tables.php) — add
 * the deferred constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "MES".mes_prod_events ADD CONSTRAINT fk_mes_prod_events_batch FOREIGN KEY (batch_id) REFERENCES "MES".mes_batches (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "MES".mes_prod_events DROP CONSTRAINT IF EXISTS fk_mes_prod_events_batch');
    }
};
