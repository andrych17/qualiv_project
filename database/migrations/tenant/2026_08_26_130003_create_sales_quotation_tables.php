<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3E/§4 Quotation Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.quot_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->uuid('quote_group_id')->default(DB::raw('gen_random_uuid()'));
            $table->integer('revision_no')->default(1);
            $table->foreignId('customer_id')->constrained('CRM.partners');
            $table->foreignId('opportunity_id')->nullable()->constrained('SALES.opp_hdrs');
            $table->foreignId('price_list_id')->nullable()->constrained('SALES.price_lists');
            $table->date('validity_date')->nullable();
            $table->string('status', 15)->default('draft'); // draft|sent|approved|accepted|declined|expired|converted
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('wne_workflow_instance_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('converted_so_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['quote_group_id', 'revision_no']);
            $table->index(['customer_id', 'status']);
            $table->index('quote_group_id');
        });

        Schema::create('SALES.quot_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quot_hdr_id')->constrained('SALES.quot_hdrs')->cascadeOnDelete();
            $table->integer('line_no');
            $table->string('item_type', 10)->default('service'); // product|service
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['quot_hdr_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.quot_lines');
        Schema::dropIfExists('SALES.quot_hdrs');
    }
};
