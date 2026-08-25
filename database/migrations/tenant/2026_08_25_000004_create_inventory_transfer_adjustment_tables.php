<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Transfers (§3F) and Adjustments (§3G), the last two MVP posting
 * engines per the suggested build order (§5: "...→ 3F/3G (transfer + adjustment) → 3K
 * (barcode wiring) — MVP ships here").
 *
 * `adjustment_reasons` is seeded with the six codes §3G names explicitly — same
 * "functionally-required default data seeded in the migration" precedent as
 * `2026_08_21_000009_create_wne_msg_templates_table.php`'s `wne.sla_breach` category; still
 * a tenant-editable lookup afterward (Master/lookup table per §4), not a hardcoded enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('source_location_id')->constrained('INVENTORY.locations');
            $table->foreignId('destination_warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('destination_location_id')->constrained('INVENTORY.locations');
            $table->date('transfer_date');
            $table->string('status', 15)->default('draft'); // draft | in_transit | completed
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('INVENTORY.transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('INVENTORY.transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->decimal('qty', 18, 4); // as entered, in `uom_id`
            $table->foreignId('uom_id')->constrained('INVENTORY.uoms');
        });

        Schema::create('INVENTORY.adjustment_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        DB::table('INVENTORY.adjustment_reasons')->insert([
            ['code' => 'count_variance', 'name' => 'Count variance', 'is_active' => true],
            ['code' => 'damage', 'name' => 'Damage', 'is_active' => true],
            ['code' => 'expiry', 'name' => 'Expiry', 'is_active' => true],
            ['code' => 'theft_loss', 'name' => 'Theft / loss', 'is_active' => true],
            ['code' => 'correction', 'name' => 'Correction', 'is_active' => true],
            ['code' => 'other', 'name' => 'Other', 'is_active' => true],
        ]);

        Schema::create('INVENTORY.adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('location_id')->constrained('INVENTORY.locations');
            $table->date('adjustment_date');
            $table->foreignId('reason_id')->constrained('INVENTORY.adjustment_reasons');
            $table->string('reference', 60)->nullable(); // e.g. a linked Cycle Count (§3O, not built yet)
            // draft | posted — pending_approval is reserved-but-unreachable: §3G's optional
            // WNE approval routing (workflow_code = inventory.adjustment_approval) needs a
            // published WNE workflow definition + a threshold config that don't exist yet,
            // same "engine ships before caller" gap already documented on Accounting's
            // JournalService for accounting.journal_approval — not wired here either.
            $table->string('status', 15)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('INVENTORY.adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('INVENTORY.adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            // Snapshot for display only, captured when the line is saved — posting always
            // re-reads the live `stock_balances` qty as the authoritative "system quantity"
            // (AdjustmentService::post()), so a stale draft never posts a wrong variance.
            $table->decimal('system_qty', 18, 4)->nullable();
            $table->decimal('counted_qty', 18, 4); // always base UoM — no per-line UoM (MVP simplification)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.adjustment_lines');
        Schema::dropIfExists('INVENTORY.adjustments');
        Schema::dropIfExists('INVENTORY.adjustment_reasons');
        Schema::dropIfExists('INVENTORY.transfer_lines');
        Schema::dropIfExists('INVENTORY.transfers');
    }
};
