<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "SYSCONFIG".tenant_modules DROP CONSTRAINT IF EXISTS tenant_modules_module_code_check');
        DB::statement('ALTER TABLE "SYSCONFIG".tenant_modules ADD CONSTRAINT tenant_modules_module_code_check CHECK (module_code IN (\'WNE\', \'DMS\', \'CRM\', \'SCHEDULE\', \'INVENTORY\', \'ACCOUNTING\', \'PURCHASE\', \'SALES\', \'HCM\', \'PAYROLL\', \'PERFORMANCE\', \'AIINSIGHT\', \'LEGAL\', \'PROJECTS\', \'MES\', \'PP\', \'POS\'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "SYSCONFIG".tenant_modules DROP CONSTRAINT IF EXISTS tenant_modules_module_code_check');
        DB::statement('ALTER TABLE "SYSCONFIG".tenant_modules ADD CONSTRAINT tenant_modules_module_code_check CHECK (module_code IN (\'WNE\', \'DMS\', \'CRM\', \'SCHEDULE\', \'INVENTORY\', \'ACCOUNTING\', \'PURCHASE\', \'SALES\', \'HCM\', \'PAYROLL\', \'PERFORMANCE\', \'AIINSIGHT\', \'LEGAL\'))');
    }
};
