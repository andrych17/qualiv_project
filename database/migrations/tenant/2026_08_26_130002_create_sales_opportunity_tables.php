<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3C/§4 Opportunity Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.opp_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('name', 200);
            $table->foreignId('customer_id')->nullable()->constrained('CRM.partners');
            $table->foreignId('lead_id')->nullable()->constrained('CRM.leads');
            $table->string('stage', 15)->default('new'); // new|qualifying|quoted|won|lost
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('sales_team_id')->nullable()->constrained('SALES.sales_teams');
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('loss_reason', 100)->nullable();
            $table->timestamps();

            $table->index(['stage', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.opp_hdrs');
    }
};
