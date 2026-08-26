<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3M/§4 Commission Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.comm_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('rep_id')->constrained('users');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 15)->default('draft'); // draft|approved|paid
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->unsignedBigInteger('wne_workflow_instance_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['rep_id', 'status']);
        });

        Schema::create('SALES.comm_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('SALES.comm_settlements')->cascadeOnDelete();
            $table->foreignId('commission_plan_id')->constrained('SALES.commission_plans');
            $table->foreignId('so_line_id')->nullable()->constrained('SALES.so_lines');
            $table->string('line_type', 10)->default('earned'); // earned|reversal
            $table->decimal('amount', 14, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.comm_settlement_lines');
        Schema::dropIfExists('SALES.comm_settlements');
    }
};
