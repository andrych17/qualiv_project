<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3L BPN Registration Tracking (LEGAL_SPECS.md). No live BPN API at this
 * scale (§5 scope note) — a manually-updated tracker. Rejection never edits in place;
 * resubmission_of_id chains a new row to the one it replaces, same non-destructive
 * philosophy as DMS versioning and deed immutability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.bpn_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deed_id')->constrained('LEGAL.deeds');
            $table->string('submission_type', 50); // balik_nama|apht_registration|split|merge|other
            $table->date('submitted_at')->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->decimal('pnbp_amount', 18, 2)->nullable();
            $table->string('status', 20)->default('prepared'); // prepared|submitted|in_process|completed|rejected
            $table->date('completed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('resubmission_of_id')->nullable()->constrained('LEGAL.bpn_submissions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.bpn_submissions');
    }
};
