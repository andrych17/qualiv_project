<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** §3Q Cycle Counting: "simple manual ABC flag on Product in v1, not computed" — a count scope option alongside location/category. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('INVENTORY.products', function (Blueprint $table) {
            $table->string('abc_class', 1)->nullable()->after('reorder_quantity'); // A | B | C
        });
    }

    public function down(): void
    {
        Schema::table('INVENTORY.products', function (Blueprint $table) {
            $table->dropColumn('abc_class');
        });
    }
};
