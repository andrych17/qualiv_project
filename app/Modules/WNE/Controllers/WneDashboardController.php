<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Services\TaskInboxService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A — WNE's own landing page: summary cards + the tabbed table
 * (My Tasks | Active Instances | SLA Breaches | DLQ / Failed Deliveries) the spec's Layout
 * section calls for. Every list here is a capped, read-only preview — each tab already has a
 * full dedicated page (My Approvals §3H, Dead Letters §3M) or, for instances, the closest
 * existing page is the owning definition's builder; this dashboard doesn't duplicate those
 * pages' filtering/pagination/actions, it's the "at a glance, then drill in" front door.
 */
class WneDashboardController extends Controller
{
    private const CAP = 25;

    public function __construct(private readonly TaskInboxService $inbox) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $today = now()->startOfDay();

        $myTasks = $this->inbox->myTasksQuery($userId)
            ->with(['step.version.definition'])
            ->urgencyFirst()
            ->limit(self::CAP)
            ->get();

        $activeInstances = WrkflowInstance::query()
            ->where('status', WrkflowInstance::STATUS_RUNNING)
            ->with('definitionVersion.definition')
            // §3A rail rule: an active instance currently sitting on a breached step reads
            // `danger` even though the instance itself is just "running" — same "breach
            // surfaces first" convention as every other module's dashboard/list built on
            // this engine (WNE_SPECS.md §3A).
            ->withExists(['steps as has_breached_step' => fn ($q) => $q
                ->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)
                ->whereNotNull('due_at')->where('due_at', '<', now())])
            ->orderByDesc('started_at')
            ->limit(self::CAP)
            ->get();

        $slaBreaches = WrkflowInstanceStep::query()
            ->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)
            ->whereNotNull('due_at')->where('due_at', '<', now())
            ->with(['step.version.definition', 'instance'])
            ->urgencyFirst()
            ->limit(self::CAP)
            ->get();

        $dlqItems = MsgDeadLetter::query()
            ->whereNull('resent_at')->whereNull('discarded_at')
            ->with('recipient')
            ->orderByDesc('created_at')
            ->limit(self::CAP)
            ->get();

        $sentToday = MsgNotification::query()->where('created_at', '>=', $today)->where('status', MsgNotification::STATUS_SENT)->count();
        $failedToday = MsgNotification::query()->where('created_at', '>=', $today)->where('status', MsgNotification::STATUS_FAILED)->count();
        $totalToday = $sentToday + $failedToday;

        return Inertia::render('WNE/Dashboard/Index', [
            'summary' => [
                'active_instances' => WrkflowInstance::query()->where('status', WrkflowInstance::STATUS_RUNNING)->count(),
                'my_pending_tasks' => $this->inbox->myTasksQuery($userId)->count(),
                // "(24h)" is a rolling window, same convention as every other card on this
                // platform that carries a day-count in its title (e.g. CRM's "Partners Added
                // (30d)") — bounded so a long-neglected breach doesn't inflate this forever;
                // the SLA Breaches *tab* below still lists every currently-open breach, unbounded.
                'sla_breaches_24h' => WrkflowInstanceStep::query()
                    ->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)
                    ->whereNotNull('due_at')
                    ->whereBetween('due_at', [now()->subDay(), now()])
                    ->count(),
                'notifications_sent_today' => $sentToday,
                'notifications_failure_rate_today' => $totalToday > 0 ? round(($failedToday / $totalToday) * 100) : null,
                'dlq_items' => MsgDeadLetter::query()->whereNull('resent_at')->whereNull('discarded_at')->count(),
            ],
            'myTasks' => $myTasks->map(fn (WrkflowInstanceStep $task) => [
                'id' => $task->id,
                'workflow_name' => $task->step->version->definition->name,
                'step_code' => $task->step->step_code,
                'status' => $task->status,
                'sla_state' => $task->sla_state,
                'due_at_formatted' => $task->due_at?->format('Y-m-d H:i'),
            ]),
            'activeInstances' => $activeInstances->map(fn (WrkflowInstance $instance) => [
                'id' => $instance->id,
                'definition_id' => $instance->definitionVersion->definition->id,
                'workflow_name' => $instance->definitionVersion->definition->name,
                'subject_type' => $instance->subject_type,
                'subject_id' => $instance->subject_id,
                'started_at_formatted' => $instance->started_at?->format('Y-m-d H:i'),
                'rail' => $instance->has_breached_step ? 'danger' : 'neutral',
            ]),
            'slaBreaches' => $slaBreaches->map(fn (WrkflowInstanceStep $step) => [
                'id' => $step->id,
                'workflow_name' => $step->step->version->definition->name,
                'step_code' => $step->step->step_code,
                'subject_type' => $step->instance->subject_type,
                'subject_id' => $step->instance->subject_id,
                'due_at_formatted' => $step->due_at?->format('Y-m-d H:i'),
            ]),
            'dlqItems' => $dlqItems->map(fn (MsgDeadLetter $dl) => [
                'id' => $dl->id,
                'channel' => $dl->channel,
                'subject' => $dl->subject,
                'recipient_email' => $dl->recipient?->email,
                'attempts' => count($dl->failure_history),
                'last_error' => collect($dl->failure_history)->last()['error'] ?? null,
                'created_at_formatted' => $dl->created_at->format('Y-m-d H:i'),
            ]),
        ]);
    }
}
