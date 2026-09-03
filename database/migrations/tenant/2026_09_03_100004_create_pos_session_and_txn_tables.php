<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS_SPECS.md §3C, §3D, §3F, §3I, §3J, §3K, §3L, §3N, §3O, §3S, §3T / §4 —
 * Sessions, Cash Movements, Transactions, Payments, Returns, and Supervisor Override Logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('POS.pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('POS.pos_terminals');
            $table->foreignId('cashier_user_id')->constrained('users');
            $table->unsignedBigInteger('cashier_employee_id')->nullable(); // informational (HCM.employees)
            $table->timestampTz('opened_at')->useCurrent();
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestampTz('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('actual_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');

            $table->index('terminal_id');
        });

        DB::statement('CREATE UNIQUE INDEX uq_pos_sessions_one_open_per_terminal ON "POS".pos_sessions (terminal_id) WHERE status = \'open\'');

        Schema::create('POS.pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('POS.pos_sessions');
            $table->string('type', 15); // cash_in | cash_out | petty_cash
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index('session_id');
        });

        Schema::create('POS.pos_txn_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->uuid('client_txn_uuid')->unique(); // §3S sync idempotency
            $table->foreignId('session_id')->constrained('POS.pos_sessions');
            $table->foreignId('terminal_id')->constrained('POS.pos_terminals');
            $table->string('receipt_number', 30)->unique();
            $table->unsignedBigInteger('customer_id')->nullable(); // informational (CRM.partners)
            $table->foreignId('table_id')->nullable()->constrained('POS.pos_tables');
            $table->string('dining_mode', 12)->default('sale'); // sale | dine_in | takeaway | delivery
            $table->unsignedBigInteger('price_list_id')->nullable(); // informational (SALES.price_lists)
            $table->string('status', 12)->default('draft'); // draft | parked | completed | voided | cancelled | refunded
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('service_charge', 14, 2)->default(0);
            $table->decimal('rounding', 10, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->boolean('is_on_account')->default(false);
            $table->unsignedBigInteger('sales_order_subject_id')->nullable(); // informational (SALES.so_hdrs)
            $table->string('park_label', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('created_offline')->default(false);
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index(['terminal_id', 'occurred_at']);
            $table->index('customer_id');
            $table->index('status');
        });

        Schema::create('POS.pos_txn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_id')->constrained('POS.pos_txn_hdrs')->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('product_id')->nullable()->constrained('INVENTORY.products');
            $table->boolean('is_open_item')->default(false);
            $table->string('description', 255);
            $table->string('uom_code', 10)->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->foreignId('batch_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->foreignId('kds_station_id')->nullable()->constrained('POS.pos_kds_stations');
            $table->string('course', 20)->nullable();
            $table->integer('seat_number')->nullable();
            $table->text('special_instruction')->nullable();
            $table->text('kitchen_note')->nullable();
            $table->string('kds_status', 12)->nullable(); // new | preparing | ready | served
            $table->boolean('inventory_posted')->default(false);

            $table->unique(['txn_id', 'line_no']);
            $table->index('txn_id');
            $table->index('product_id');
        });

        Schema::create('POS.pos_txn_line_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_line_id')->constrained('POS.pos_txn_lines')->cascadeOnDelete();
            $table->foreignId('modifier_id')->constrained('POS.pos_modifiers');
            $table->string('modifier_name', 100);
            $table->decimal('price_delta', 14, 2)->default(0);

            $table->index('txn_line_id');
        });

        Schema::create('POS.pos_kds_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_line_id')->constrained('POS.pos_txn_lines');
            $table->string('status', 12); // new | preparing | ready | served | refired
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();

            $table->index('txn_line_id');
        });

        Schema::create('POS.pos_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_id')->constrained('POS.pos_txn_hdrs')->cascadeOnDelete();
            $table->string('method', 15); // cash | card | qris | bank_transfer | e_wallet | voucher | gift_card | store_credit | customer_credit | on_account
            $table->decimal('amount', 14, 2);
            $table->string('reference', 100)->nullable();
            $table->decimal('change_given', 14, 2)->default(0);
            $table->foreignId('gift_card_id')->nullable()->constrained('POS.pos_gift_cards');
            $table->foreignId('store_credit_id')->nullable()->constrained('POS.pos_store_credits');
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index('txn_id');
        });

        Schema::create('POS.pos_return_hdrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_txn_id')->constrained('POS.pos_txn_hdrs');
            $table->foreignId('session_id')->constrained('POS.pos_sessions');
            $table->string('reason_code', 30);
            $table->string('status', 12)->default('requested'); // requested | approved | completed | rejected
            $table->string('refund_method', 15)->nullable();
            $table->boolean('without_receipt')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('original_txn_id');
        });

        Schema::create('POS.pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('POS.pos_return_hdrs')->cascadeOnDelete();
            $table->foreignId('original_txn_line_id')->nullable()->constrained('POS.pos_txn_lines');
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_price', 14, 2);
            $table->string('condition_note', 255)->nullable();
            $table->boolean('restockable')->default(true);

            $table->index('return_id');
        });

        Schema::create('POS.pos_override_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('txn_id')->nullable()->constrained('POS.pos_txn_hdrs');
            $table->foreignId('session_id')->nullable()->constrained('POS.pos_sessions');
            $table->string('action_type', 30); // discount_above_threshold | item_void | sale_void | refund | price_override | drawer_open | session_reopen
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('authorized_by')->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index('txn_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('POS.pos_override_logs');
        Schema::dropIfExists('POS.pos_return_lines');
        Schema::dropIfExists('POS.pos_return_hdrs');
        Schema::dropIfExists('POS.pos_payments');
        Schema::dropIfExists('POS.pos_kds_ticket_events');
        Schema::dropIfExists('POS.pos_txn_line_modifiers');
        Schema::dropIfExists('POS.pos_txn_lines');
        Schema::dropIfExists('POS.pos_txn_hdrs');
        Schema::dropIfExists('POS.pos_cash_movements');
        Schema::dropIfExists('POS.pos_sessions');
    }
};
