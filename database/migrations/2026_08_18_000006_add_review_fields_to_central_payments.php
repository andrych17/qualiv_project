<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_payments', function (Blueprint $table) {
            // R2 object key for the uploaded proof of payment (CENTRAL_SPECS.md §4).
            $table->string('receipt_object_key')->nullable()->after('amount');
            // pending_review -> confirmed / rejected (§3F). Retained (never deleted) either way.
            $table->string('status')->default('pending_review')->after('method');
            // paid_at now means "the tenant's claimed transfer date" at submission time.
            $table->timestamp('submitted_at')->nullable()->after('paid_at');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('central_admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });

        // Every row that already exists at this point was created under the pre-§3F flow
        // (CentralPaymentService::recordAndMarkPaid()), where recording a payment always meant
        // it was already settled — the new 'pending_review' default is only correct for rows
        // created going forward via submit(). Backfill history so it isn't mislabeled as
        // awaiting review on a patched staging/production database.
        DB::table('central_payments')->update([
            'status' => 'confirmed',
            'reviewed_at' => DB::raw('COALESCE(paid_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('central_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['receipt_object_key', 'status', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
