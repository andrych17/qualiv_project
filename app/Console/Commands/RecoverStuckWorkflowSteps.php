<?php

namespace App\Console\Commands;

use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Console\Command;

/**
 * WNE §3C recovery sweep. Tenant-scoped — run per tenant via stancl's
 * `tenants:run`, never directly (this command has no tenant iteration of
 * its own; it assumes the tenant DB connection is already switched).
 */
class RecoverStuckWorkflowSteps extends Command
{
    protected $signature = 'wne:recover-stuck-workflow-steps {--grace=30 : Minutes a step may sit in_progress before it is re-driven}';

    protected $description = 'Re-drive workflow instance steps stuck in_progress past the grace period (§3C fault tolerance)';

    public function handle(WorkflowService $workflow): int
    {
        $recovered = $workflow->recoverStuckSteps((int) $this->option('grace'));

        if ($recovered > 0) {
            $this->info("Recovered {$recovered} stuck workflow step(s).");
        }

        return self::SUCCESS;
    }
}
