<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3E — Resource & Resource Group Reference, per PP_SPECS.sql's DDL for this
 * section. `pp_resources` is only for resource *types* no other Core module owns yet (tool,
 * tank, utility, warehouse-as-capacity) — machine/work-center identity stays in
 * `MES.mes_work_centers`/`mes_machines`/`mes_stations` (not built yet), and labor stays in
 * `HCM.shifts`. `pp_resource_group_members.resource_ref_id` is a genuinely polymorphic-by-type
 * column (its meaning depends on `resource_type`), so it gets no DB FK even for the
 * `pp_resource` case — resolved and validated at the app layer (§5 discipline), same posture
 * `pp_planned_orders.source_type`/`source_id` already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_resources', function (Blueprint $table) {
            $table->id();
            $table->string('type', 15); // tool | tank | utility | warehouse — app-validated
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->decimal('capacity', 18, 4)->nullable();
            $table->string('uom_code', 10)->nullable();
            $table->string('external_type', 20)->nullable(); // informational — aliases an MES/HCM resource
            $table->unsignedBigInteger('external_id')->nullable(); // informational
            $table->boolean('is_active')->default(true);
        });

        Schema::create('PP.pp_resource_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('PP.pp_resource_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_group_id')->constrained('PP.pp_resource_groups')->cascadeOnDelete();
            $table->string('resource_type', 20); // mes_work_center | mes_machine | mes_station | pp_resource — app-validated
            $table->unsignedBigInteger('resource_ref_id'); // informational for mes_* types; PP.pp_resources.id for pp_resource (app-resolved)

            $table->unique(['resource_group_id', 'resource_type', 'resource_ref_id'], 'uq_pp_resource_group_members');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_resource_group_members');
        Schema::dropIfExists('PP.pp_resource_groups');
        Schema::dropIfExists('PP.pp_resources');
    }
};
