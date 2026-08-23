<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3Q bank reconciliation. Adds the match pointer §3F's
 * bank_statement_lines deliberately left out (nothing wrote it yet — see that
 * migration's docblock). A nullable-unique FK: many statement lines can stay
 * unmatched (null), but a posted gl_journal_lines row can be claimed by at
 * most one statement line — BankReconciliationService still validates the
 * account match independently (the FK only guarantees the line exists, not
 * that it belongs to the right GL account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('matched_journal_line_id')->nullable()->unique()->constrained('ACCOUNTING.gl_journal_lines');
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('ACCOUNTING.bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_journal_line_id');
            $table->dropColumn('matched_at');
            $table->dropConstrainedForeignId('matched_by');
        });
    }
};
