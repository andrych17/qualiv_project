<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The (scope_type, scope_id) unique constraint can't dedupe platform_default rows:
        // PostgreSQL treats NULLs as distinct, so a second platform_default row would slip
        // through. A filtered unique index enforces "exactly one platform default".
        DB::statement(
            "CREATE UNIQUE INDEX central_dunning_policies_platform_default_uniq
             ON central_dunning_policies (scope_type)
             WHERE scope_type = 'platform_default'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS central_dunning_policies_platform_default_uniq');
    }
};
