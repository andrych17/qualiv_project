<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3L/§4 Contracts & Subscriptions Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.contr_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('customer_id')->constrained('CRM.partners');
            $table->string('name', 200);
            $table->date('term_start');
            $table->date('term_end');
            $table->boolean('auto_renew')->default(false);
            $table->string('status', 15)->default('draft'); // draft|active|renewed|cancelled|expired
            $table->foreignId('price_list_id')->nullable()->constrained('SALES.price_lists');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('SALES.contr_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contr_hdr_id')->constrained('SALES.contr_hdrs')->cascadeOnDelete();
            $table->integer('line_no');
            $table->string('item_type', 10)->default('service'); // product|service
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->decimal('recurring_amount', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('billing_interval', 10); // monthly|quarterly|annual
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['contr_hdr_id', 'line_no']);
        });

        Schema::create('SALES.recurring_billing_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contr_subscription_id')->constrained('SALES.contr_subscriptions')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('CRM.partners');
            $table->date('next_bill_date');
            $table->timestampTz('last_billed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('next_bill_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.recurring_billing_schedules');
        Schema::dropIfExists('SALES.contr_subscriptions');
        Schema::dropIfExists('SALES.contr_hdrs');
    }
};
