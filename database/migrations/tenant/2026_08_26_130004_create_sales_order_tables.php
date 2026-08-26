<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3F/§4 Sales Order Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.so_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('so_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('CRM.partners');
            $table->foreignId('quote_id')->nullable()->constrained('SALES.quot_hdrs');
            $table->foreignId('price_list_id')->nullable()->constrained('SALES.price_lists');
            $table->string('status', 20)->default('draft'); // draft|confirmed|partially_fulfilled|fulfilled|cancelled
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('wne_workflow_instance_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('SALES.quot_hdrs', function (Blueprint $table) {
            $table->foreign('converted_so_id')->references('id')->on('SALES.so_hdrs');
        });

        Schema::create('SALES.so_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('so_hdr_id')->constrained('SALES.so_hdrs')->cascadeOnDelete();
            $table->integer('line_no');
            $table->string('item_type', 10)->default('service'); // product|service
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->decimal('qty_ordered', 14, 3);
            $table->decimal('qty_delivered', 14, 3)->default(0); // derived rollup from dlv_lines
            $table->decimal('qty_invoiced', 14, 3)->default(0);  // derived rollup from Accounting
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['so_hdr_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::table('SALES.quot_hdrs', function (Blueprint $table) {
            $table->dropForeign(['converted_so_id']);
        });

        Schema::dropIfExists('SALES.so_lines');
        Schema::dropIfExists('SALES.so_hdrs');
    }
};
