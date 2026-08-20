<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CRM module — Partner Merge / Deduplication (§3G) audit trail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CRM.partner_merge_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_from_partner_id')->constrained('CRM.partners');
            $table->foreignId('merged_into_partner_id')->constrained('CRM.partners');
            $table->foreignId('merged_by')->nullable()->constrained('users');
            $table->timestamp('merged_at')->useCurrent();
            $table->json('field_conflicts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CRM.partner_merge_log');
    }
};
