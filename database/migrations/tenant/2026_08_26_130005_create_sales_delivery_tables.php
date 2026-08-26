<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3H/§4 Delivery Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.dlv_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('so_hdr_id')->constrained('SALES.so_hdrs');
            $table->string('status', 15)->default('pending'); // pending|picked|packed|shipped|delivered|cancelled
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->unsignedBigInteger('source_location_id')->nullable();       // INVENTORY.locations.id
            $table->unsignedBigInteger('inventory_goods_issue_id')->nullable(); // INVENTORY.goods_issues.id
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();

            $table->index('so_hdr_id');
        });

        Schema::create('SALES.dlv_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dlv_hdr_id')->constrained('SALES.dlv_hdrs')->cascadeOnDelete();
            $table->foreignId('so_line_id')->constrained('SALES.so_lines');
            $table->decimal('qty_shipped', 14, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.dlv_lines');
        Schema::dropIfExists('SALES.dlv_hdrs');
    }
};
