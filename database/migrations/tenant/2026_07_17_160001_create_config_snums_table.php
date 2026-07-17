<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SysConfig serial counters (netapp1 config_snums) — per-tenant document numbering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SYSCONFIG.config_snums', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->bigInteger('last_cnt')->default(0);
            $table->bigInteger('wrap_low')->default(1);
            $table->bigInteger('wrap_high')->default(999999);
            $table->integer('step_cnt')->default(1);
            $table->text('descr')->nullable();
            $table->string('status_code', 5)->default('A');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SYSCONFIG.config_snums');
    }
};
