<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3D / §4 — Equipment Master Data: Plant (tenant) → Area/Line → Work Center →
 * Machine → Station. Per MES_SPECS.sql's DDL for this section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_work_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('area_line', 100)->nullable();
            $table->string('type', 20)->default('discrete'); // discrete | process — app-validated
            $table->timestamps();
        });

        Schema::create('MES.mes_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained('MES.mes_work_centers');
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            // running | idle | down | maintenance | setup | waiting_material | waiting_operator | waiting_qc — app-validated
            $table->string('status', 20)->default('idle');
            $table->timestamps();

            $table->index('work_center_id');
        });

        Schema::create('MES.mes_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->nullable()->constrained('MES.mes_work_centers');
            $table->foreignId('machine_id')->nullable()->constrained('MES.mes_machines');
            $table->string('code', 30)->unique();
            $table->string('name', 150);
        });

        // §3D: "the physical spot an operator executes at" — must hang off a work center or a
        // machine (or both), never neither.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE "MES".mes_stations ADD CONSTRAINT chk_mes_stations_owner CHECK (work_center_id IS NOT NULL OR machine_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_stations');
        Schema::dropIfExists('MES.mes_machines');
        Schema::dropIfExists('MES.mes_work_centers');
    }
};
