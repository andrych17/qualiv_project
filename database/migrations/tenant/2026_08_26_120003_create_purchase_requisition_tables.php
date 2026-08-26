<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PURCHASE module — §3B Purchase Requisition (PR), the spine's first step. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_requisition_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('pr_no', 30)->unique();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('cost_center_id')->nullable()->constrained('PURCHASE.cost_centers');
            $table->date('needed_by')->nullable();
            // optional polymorphic link back to the triggering record (e.g. legal.matters)
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 20)->default('draft'); // draft|pending_approval|approved|rejected|converted|cancelled
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->boolean('budget_warning')->default(false); // soft budget check flag (§3B/§3F)
            $table->boolean('duplicate_warning')->default(false); // soft duplicate-PR check flag
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['status', 'requester_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('PURCHASE.pur_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('PURCHASE.pur_requisition_hdrs')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('catalog_item_id')->nullable()->constrained('PURCHASE.pur_catalog_items');
            $table->string('description', 255);
            $table->decimal('qty', 18, 4);
            $table->decimal('estimated_unit_price', 18, 2)->default(0);
            $table->foreignId('category_id')->nullable()->constrained('PURCHASE.categories');
            $table->decimal('local_content_pct', 5, 2)->nullable(); // §3M ESG MVP: flag only, no enforcement
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_requisition_lines');
        Schema::dropIfExists('PURCHASE.pur_requisition_hdrs');
    }
};
