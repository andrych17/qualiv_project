<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE module — §3C Sourcing/RFx. MVP scope: RFQ only, flat comparison —
 * `type` column reserves room for RFI/RFP later without a breaking change (§2/§4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_rfx_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('rfx_no', 30)->unique();
            $table->string('type', 5)->default('rfq'); // rfq|rfi|rfp (rfi/rfp reserved, Future Version)
            $table->foreignId('pr_id')->nullable()->constrained('PURCHASE.pur_requisition_hdrs');
            $table->date('due_date');
            $table->string('status', 20)->default('draft'); // draft|sent|responses_open|awarded|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_rfx_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfx_id')->constrained('PURCHASE.pur_rfx_hdrs')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->decimal('qty', 18, 4);
            $table->foreignId('awarded_supplier_id')->nullable()->constrained('CRM.partners');
        });

        Schema::create('PURCHASE.pur_rfx_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfx_id')->constrained('PURCHASE.pur_rfx_hdrs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('CRM.partners');
            $table->uuid('response_token')->unique(); // lightweight signed-link response, no supplier login (§2/§5)
            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('responded_at')->nullable();

            $table->unique(['rfx_id', 'supplier_id']);
        });

        Schema::create('PURCHASE.pur_rfx_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('PURCHASE.pur_rfx_invitations')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_rfx_response_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('PURCHASE.pur_rfx_responses')->cascadeOnDelete();
            $table->foreignId('rfx_line_id')->constrained('PURCHASE.pur_rfx_lines');
            $table->decimal('price', 18, 2);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_rfx_response_lines');
        Schema::dropIfExists('PURCHASE.pur_rfx_responses');
        Schema::dropIfExists('PURCHASE.pur_rfx_invitations');
        Schema::dropIfExists('PURCHASE.pur_rfx_lines');
        Schema::dropIfExists('PURCHASE.pur_rfx_hdrs');
    }
};
