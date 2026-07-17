# W.N.E. Module
## Workflow and Notification Engine — Core Shared Module

# 1. Backgrounds

> Pain point and business value.

Every module in the ERP (Purchasing, Sales, HR, Finance, Inventory, etc.) eventually needs two
recurring capabilities: **something has to be approved before it proceeds**, and **someone has to
be told when something happens**. Left to each module, this gets solved independently:

- Each module hardcodes its own approval logic (if/else chains, hardcoded approver IDs) — not
  reusable, not auditable, breaks when org structure changes.
- Each module hardcodes its own notification logic (mail() calls scattered everywhere) — no
  central log, no retry, inconsistent formatting, no per-tenant channel preference.
- No single place to see "what's pending my approval" or "what got sent to whom, and did it
  fail" across the whole system.
- Adding a new channel (WhatsApp, push, in-app bell) means touching every module instead of
  configuring once.

**Client requirements:**
- Multi-tenant aware — workflow rules and notification templates/channels are configurable
  *per tenant*, not global.
- Any module should be able to trigger a workflow or send a notification without knowing
  *how* delivery happens (decoupled via events, not direct calls).
- Must support conditional, multi-step, multi-approver workflows (not just single-approver).
- Must support multiple delivery channels per notification (email + in-app simultaneously).
- Must be auditable: every workflow transition and every notification attempt is logged.
- Must degrade gracefully — a failed SMS provider should not block the business transaction
  that triggered it.

# 2. Goals

> Designated features solving the Backgrounds above.

- **Centralized, decoupled service.** Other modules integrate via a published Event
  (`WorkflowRequested`, `NotificationRequested`) or a thin internal `MessagingService`
  facade/API — never by calling channel providers or workflow logic directly. This keeps the
  system monolithic-modular: MSG lives in the same codebase/deploy unit, but is boundary-clean
  enough to extract into a microservice later if load demands it.
- **No-code Workflow Builder.** Admins define approval/state-machine flows (states,
  transitions, conditions, approvers, timeouts) through configuration screens — no code
  deploy needed to change who approves what.
- **Configurable Notification Engine.** Admins define templates, channel routing, and
  recipient rules per event type, per tenant.
- **Multi-channel delivery** — Email, SMS, WhatsApp (API), Push, In-App — pluggable via a
  channel driver interface (`ChannelDriverInterface`) so new channels are additive, not
  invasive.
- **Reliability** — queued dispatch, automatic retry with backoff, dead-letter logging for
  manual inspection.
- **Traceability** — full audit trail: who approved/rejected what and when; what was sent,
  to whom, via which channel, and delivery status.
- **Reusability** — one workflow engine instance is shared by all modules; a "Purchase Order
  Approval" workflow and a "Leave Request Approval" workflow both run on the same engine,
  differing only in configuration.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.
> **MVP-first** — each form below ships the simplest version that's still genuinely usable and
> sellable. Anything that adds real implementation cost without blocking a first working release
> is pushed to **3F. Future Version**, not built now. The schema in `wne_schema.sql` already has
> the columns these future features need (`sla_hours`, `quorum_rule`, `group_no`, etc.), so
> deferring the *logic* doesn't cost a breaking migration later.

## 3A. Main Dashboard

**Function / features**
- Two lists, tab-switched: **"My Approvals"** (tasks assigned to me, by direct user match only
  in v1) and **"Notification Log"** (recent sends + status, tenant-wide).
- Approve / Reject directly from the "My Approvals" row, with an optional remarks field —
  no separate detail page required to act.

**Layout**
- Simple tabbed data table (shared component per `DESIGN.md`), no summary cards/analytics in v1.
- Status Rail per row: `warning` = pending, `success` = approved, `danger` = rejected/failed,
  `info` = queued.
- Row click opens a drawer with the workflow's history (a flat list of `wrkflow_task_actions`
  for that instance) or the notification's payload + attempts.

**Rules / logic**
- Tenant-scoped by default global scope.
- "My Approvals" resolves via **direct user assignment only** (`assignee_type = 'user'`) in v1
  — role/group-based task visibility is 3F.

## 3B. Workflow Configuration

**Purpose:** let an admin define a workflow without a code deploy — via plain forms, not a
visual canvas.

- **Definition form:** code, name, owning module, version, active toggle.
- **States sub-form:** add/remove rows — code, name, is_initial, is_final, `sla_hours`
  (field exists and is stored in v1; nothing acts on it yet — see 3F).
- **Transitions sub-form:** from-state / to-state / action dropdowns, name.
- **Approver (per transition):** a **single** approver entry — either one specific user or one
  role. `quorum_rule` is stored but v1 only implements the `any` (single approver) path — see
  3F for multi-approver quorum.
- **Conditions (per transition):** a flat list of `field / operator / value` rows, all
  combined with **AND** only in v1 (`group_no` always `1`) — multi-group OR logic is 3F.
- All of this is standard CRUD screens (data table + modal form per `DESIGN.md`), which is
  dramatically faster to build than a drag-and-drop state/transition canvas and is just as
  usable for the handful of workflows a tenant configures at launch.

## 3C. Messaging Control

**Purpose:** configure *what* gets sent, *how*, and *to whom* — kept to flat, predictable forms.

- **Event Catalog:** read-only list, seeded by each module's migration/seeder. Admins pick
  from existing event codes; creating a *new* event code is a developer task in v1, not an
  admin-facing form (adding one is genuinely a code change — a new trigger point — so this
  isn't a shortcut, it's the correct scope).
- **Templates:** flat form — event, channel, subject (email only), body as plain text with
  `{{merge_field}}` placeholders. One template per event × channel × the tenant's default
  locale. No WYSIWYG editor, no live preview, no multi-locale variants in v1.
- **Channel Configs:** one active provider per channel per tenant — a simple credentials form
  (API key/host fields per provider). No multi-provider failover in v1.
- **Routing Rules:** a table of event × channel × recipient type
  (`static_user` / `static_role` / `workflow_approver` / `record_owner`), one active rule per
  combination, no time-of-day/quiet-hours logic in v1.
- **User preference:** a single opt-in/opt-out toggle per channel, per user — no granular
  per-event preferences in v1.

## 3D. Workflow Engine

**Purpose:** the runtime that executes 3B's definitions — synchronous and straightforward.

- **Trigger intake:** listens for `WorkflowRequested` (payload: `workflow_code`, `subject_type`,
  `subject_id`, `initiator_id`, `context` JSON). Creates a `wrkflow_instance`, evaluates the
  transitions leaving the initial state **top-to-bottom, first match wins** (no priority
  weighting logic in v1), and creates one `wrkflow_task` for the configured approver.
- **Approver action:** Approve/Reject writes a `wrkflow_task_action`, immediately advances the
  instance to the matched transition's target state (or finalizes if that state `is_final`).
  Because v1 approvers are single (3B), there's no quorum tally step — the one action *is* the
  decision.
- **State-change hand-off:** on every state change, synchronously fires an internal
  `WorkflowStateChanged` event to the Notification engine (3E) in the same request — only the
  actual channel send is queued, not this internal hand-off. Keeps the runtime simple to trace
  and debug for a solo dev.
- **Completion callback:** on reaching a final state, fires a plain `WorkflowCompleted` event
  (`instance_id`, `status`, `subject_type`, `subject_id`) that the owning module listens to and
  uses to update its own record — WNE never writes into another module's tables.

## 3E. Notification Dispatch Engine

**Purpose:** turn a `WorkflowStateChanged` (or any other module's `NotificationRequested`)
into an actual message, reliably enough to trust, simply enough to ship fast.

- Queued job per (recipient × channel): resolve the matching template + routing rule, check
  `msg_user_preferences` for an opt-out, render merge fields, call
  `ChannelDriverInterface::send()`, write one `msg_notification_log` row and one
  `msg_notification_attempts` row.
- **Retry:** a single fixed-delay retry (e.g. 60s) on failure, then mark `dead_letter` — no
  exponential backoff curve in v1.
- **Driver build order:** ship **Email** and **In-App** first — both have low/zero external
  setup cost (In-App is just a row the frontend reads on load) — then add SMS/WhatsApp/Push
  drivers once a provider is chosen. Each new driver is one class implementing
  `ChannelDriverInterface`; no engine changes required either way.

## 3F. Future Version (explicitly deferred — do not build now)

- **No-code visual Workflow Builder** — drag-and-drop canvas for states/transitions, replacing
  3B's flat forms.
- **Dynamic approver resolver framework** (e.g. "requester's manager", "cost-center owner") —
  needs integration with HR/org-chart data that doesn't exist yet; v1 approvers are a fixed
  user or role only.
- **Multi-approver quorum** (`any` / `all` / `majority` computation) — column already exists
  (`quorum_rule`), logic to tally multiple approvers on one transition is future work.
- **Multi-group AND/OR condition builder** — v1 ships a single AND group only
  (`group_no` support exists in the schema for this reason).
- **Role/group-based "My Approvals"** — v1 dashboard shows direct user assignments only.
- **SLA timeout & auto-escalation** (scheduled job acting on `sla_hours`) — the column is
  populated from day one precisely so this doesn't need a later migration.
- **Delegation-aware task auto-reassignment** — `wrkflow_delegations` exists for record-keeping
  today; the engine doesn't yet route tasks to a delegate automatically.
- **Template versioning, live preview, and test-send tooling.**
- **Multi-locale template sets** beyond the tenant's default locale.
- **Notification digest/batching** — collapsing multiple notifications into one summary.
- **Exponential backoff retry + a dead-letter manual-resend admin screen** — v1 is one retry,
  then dead-letter for manual DB inspection.
- **Real-time in-app delivery** (WebSocket/Pusher push) — v1 is poll/read-on-load.
- **Multi-provider automatic failover** per channel (e.g. SES fails over to backup SMTP).
- **Cross-module analytics** (approval turnaround time, deliverability rate, SLA breach trends).

**Suggested build order for Claude Code:** 3B (definitions/states/transitions/approvers,
single-AND conditions) → 3D (trigger intake + single-approver execution) → 3C (templates,
channel configs, routing rules) → 3E (Email + In-App drivers first) → 3A (dashboard) — ship at
this point — then revisit 3F items as real usage/revenue justifies each one.

# 4. Technical Notes

> All necessary techical detail to help AI Coding

**Architecture pattern:** Monolithic-modular. `Messaging` is a first-class module inside the
same Laravel app/deploy, exposing:
- **Internal facade/service** — `MessagingService::requestWorkflow(...)`,
  `MessagingService::notify(...)` — for same-process modules (preferred, lowest latency).
- **Internal event bus** (Laravel events/listeners, or a lightweight domain-event dispatcher) —
  decouples callers from MSG internals; this is the seam that would let MSG be split out to a
  microservice later without rewriting callers.
- **Optional REST/internal API** — only if/when a module needs to run out-of-process (e.g. a
  separate mobile backend or a future microservice) — kept thin, mirrors the facade.

**Suggested core tables**
- `wne.workflow_definitions`, `wne.workflow_states`, `wne.workflow_transitions`
- `wne.workflow_instances`, `wne.workflow_tasks`, `wne.workflow_task_actions`
- `wne.event_catalog`, `wne.templates`, `wne.channel_configs`, `wne.routing_rules`
- `wne.notification_log`, `wne.notification_attempts`
- All tenant-scoped tables carry `tenant_id` + standard global scope.

**Queues:** dedicated queue(s) for notification dispatch (`notifications`) separate from
workflow evaluation (`workflow`), so a slow SMS provider never delays approval-state
processing.

**Extensibility:** new channel = new class implementing `ChannelDriverInterface`
(`send($recipient, $renderedTemplate): DeliveryResult`), registered in a driver map — no
core engine changes required.
