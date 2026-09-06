<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schemas = [
            'SYSCONFIG',
            'WNE',
            'DMS',
            'CRM',
            'SCHEDULE',
            'INVENTORY',
            'ACCOUNTING',
            'SALES',
            'PURCHASE',
            'HCM',
            'PAYROLL',
            'PERF',
            'PROJECTS',
            'AIINSIGHT',
            'CUSTOMFIELDS',
            'LEGAL',
            'PP',
            'MES',
            'POS',
        ];

        foreach ($schemas as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schemas are kept on rollback to avoid destructive cascading drops
    }
};
