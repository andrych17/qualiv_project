<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('destination_location_id')->nullable()->change();
        });

        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->foreignId('source_location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->foreignId('source_location_id')->nullable(false)->change();
        });

        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('destination_location_id')->nullable(false)->change();
        });
    }
};
