<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3E dependency: a withholding type's `code` (e.g. 'PPh23') doesn't map cleanly to
 * TaxBuktiPotong::TYPES ('BP23' etc — and 'PPh22'/'PPh15' don't map to any BP type at
 * all), so BuktiPotongService::issue()'s $bpType can't be safely derived from the code
 * string. Explicit configuration beats string-guessing (§2 customization ladder) —
 * nullable so it doesn't break any withholding_types rows already seeded in §3M;
 * ApBillService::post() fails loud if a bill needs one and it's unset, same treatment
 * as `companies.ar_control_account_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.withholding_types', function (Blueprint $table) {
            $table->string('bp_type', 10)->nullable()->after('code'); // BP21|BP26|BP23|BP4A2|BPU
        });
    }

    public function down(): void
    {
        Schema::table('ACCOUNTING.withholding_types', function (Blueprint $table) {
            $table->dropColumn('bp_type');
        });
    }
};
