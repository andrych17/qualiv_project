<?php

namespace App\Console\Commands;

use App\Modules\WNE\Services\SlaEscalationService;
use Illuminate\Console\Command;

/**
 * WNE §3F escalation sweep. Tenant-scoped — run per tenant via stancl's
 * `tenants:run`, same convention as wne:recover-stuck-workflow-steps (§3C).
 */
class EscalateBreachedWorkflowSteps extends Command
{
    protected $signature = 'wne:escalate-breached-workflow-steps';

    protected $description = 'Escalate workflow steps that breached their SLA due_at (§3F)';

    public function handle(SlaEscalationService $escalation): int
    {
        $escalated = $escalation->escalateBreachedSteps();

        if ($escalated > 0) {
            $this->info("Escalated {$escalated} SLA-breached workflow step(s).");
        }

        return self::SUCCESS;
    }
}
