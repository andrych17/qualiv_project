# WNE Module
## Workflow & Notification Engine — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every module in the ERP eventually needs two things: **something has to move through a
sequence of decisions/approvals**, and **someone has to be told about it**. Left unsolved
centrally, this repeats the exact anti-pattern every later Core module (DMS, CRM, Schedule,
Accounting, HCM, Inventory, Legal, Purchase, Sales) was explicitly designed to avoid by
depending on this one:

- Each module invents its own "status" field and if/else approval logic — no shared audit
  trail, no shared way to see "what's waiting on me" across the whole ERP, no reusable
  escalation behavior when something sits too long.
- Each module invents its own way to send an email/SMS/push — inconsistent templates, no
  shared delivery tracking, no way to honor a user's "don't SMS me after 8pm" preference once,
  centrally, instead of per-module.
- No single place to answer "what's overdue, what's waiting on me, what's done" — this is the
  core promise of the platform's **Status Rail** motif (`DESIGN.md`), and it only works if
  every module's approvals/notifications flow through one engine with one state model.
- No fault-tolerant process tracking — if the app restarts mid-approval-chain today, there is
  no module-agnostic way to know where a given process actually was.
- No decoupling between "a business event happened" and "who gets told, how, and when" — every
  module would otherwise hardcode `Mail::send(...)` calls scattered across the codebase.

**Client requirements:**
- Must work **fully standalone** — a tenant can run WNE with nothing else installed, driving
  simple internal approval chains and notifications, since it's sellable as its own line item
  (generic business-process automation), same posture as every other Core module.
- Must also be the **first thing every other module reaches for** — WNE is Core module #1 in
  the build order (`CLAUDE.md` §5) precisely because Notifications/Workflows underpin
  everything else; every later module's spec assumes WNE already exists and reuses it rather
  than building parallel approval/notification logic.
- Multi-tenant aware, same DB-per-tenant isolation as every other Core module — no `tenant_id`
  column (per `CLAUDE.md` §4/§7; this module follows that rule cleanly).
- Decoupled from every module that uses it: WNE exposes a facade (`WorkflowService`,
  `MessagingService`) and consumes/publishes events (`WorkflowRequested`,
  `NotificationRequested`, `WorkflowStepCompleted`, ...) — it never reaches into a calling
  module's schema, and a calling module never reaches into WNE's internals beyond the facade.
- Process definitions must be changeable by a non-developer (admin/business analyst) without a
  code deploy — this is the whole point of a "low-code" workflow engine for a solo dev who
  can't personally rebuild every tenant's approval chain by hand.
- Notification delivery must survive traffic spikes and provider outages without silently
  losing a message — retries, dead-letter handling, and delivery tracking are not optional for
  a product marketed on "trust, precision" (`DESIGN.md`).
- Users must control how they're notified (channel, quiet hours, category opt-out) — both a
  UX expectation and, for channels like SMS/email, a compliance expectation.

# 2. Goals

> Designated features. **MVP-first** — this module blocks every other module's build, so speed
> to a correct, reusable core matters more than shipping every advanced capability on day one.
> Complex/advanced items are explicitly pushed to Future Version below, per the brief.

## In scope for v1 (MVP — quick implementation)

**Workflow Engine**
- **Structured workflow definitions** — steps, transitions, and conditions modeled as
  structured data (JSON-backed step/transition rows), authored through a **form-based builder**
  (add step → set type → set condition → connect to next step) with a **read-only visual
  preview** of the resulting flow (rendered as a simple node/arrow diagram). This gets the
  *value* of "model a process without writing code" into v1 fast; a true drag-and-drop
  canvas editor is Future Version (see below) — the schema is designed so the canvas is
  purely a new UI over the same `wrkflow_steps`/`wrkflow_transitions` tables, not a rework.
- **State management & persistence** — every workflow instance and every step within it is a
  durable DB row with an explicit status (`pending`/`in_progress`/`completed`/`failed`/
  `skipped`/`cancelled`). Nothing about "where a process is" lives only in memory or a queue
  job — a server restart never loses process state, since the queue job is only ever "resume
  from persisted state," never "hold state."
- **Dynamic routing & branching (v1 scope)** — sequential steps, conditional branches
  (if/then on a field from the triggering payload), and simple parallel steps (fan-out to N
  steps, join when all/any complete). Dynamic task **assignment** supports: a specific user, a
  role, a team, or "the record's owner" (resolved from the payload) — covers the large
  majority of real approval chains without a full business-rules-engine DSL.
- **Version control** — a definition has draft/published/unpublished states; publishing
  creates an immutable version row. An in-flight instance always finishes on the version it
  started with (`wrkflow_instances.definition_version_id` is fixed at start) — never
  mid-flight-upgraded, exactly per the requirement.
- **SLA & escalation (v1 scope)** — a duration timer per step (`sla_hours`); on breach, the
  engine reassigns/adds an escalation target (a different user/role) and fires a
  `NotificationRequested` event — no separate escalation code path, it's the same
  notification mechanism as everything else in this module.
- **API & webhook extensibility (v1 scope)** — outbound: any step can fire a webhook (URL +
  payload template) via a queued job with retry (reuses the same retry/backoff mechanism as
  Notification delivery, §3M, rather than a second implementation). Inbound: a step can be
  `waiting_for_callback` — the engine issues a signed callback token/URL; when an external
  system POSTs back to it, the matching instance step resumes. This is the seam that lets a
  future payment gateway, e-signature provider, or government API (e.g. Legal's Coretax/BPN
  tracking) resume a workflow without WNE knowing anything about that integration.
- **My Approvals / Task Inbox** — the shared "what's waiting on me" queue every other module's
  dashboard already assumes exists (CRM, HCM, Accounting, Purchase, Sales, Legal all reference
  this pattern) — resolved via direct assignment **and** role/team membership.

**Notification Module**
- **Multi-channel support (v1 scope)** — pluggable `ChannelDriverInterface` (`send($message):
  DeliveryResult`), with v1 real drivers for **Email** (SMTP/SendGrid) and **In-App** (DB-backed
  + WebSocket push via Laravel Reverb/Pusher-compatible broadcasting for real-time badges/
  toasts). **SMS (Twilio)** and **Push (FCM/APNs)** ship as the same driver interface with
  working v1 implementations behind a per-tenant enable flag — a tenant not needing SMS/Push
  yet simply doesn't configure credentials; no separate build later, just configuration.
- **User Preference Center (v1, core to compliance — not trimmed)** — per user: preferred
  channel(s) per notification category, quiet hours (start/end, tenant timezone), and a hard
  opt-out toggle per category (security-critical categories, e.g. "password reset," can be
  marked non-opt-outable by the category definition itself).
- **Message queuing & async processing (v1 = Redis-backed Laravel queues)** — every
  notification is dispatched as a queued job onto a dedicated `notifications` queue (already
  the shared queue every other module's spec references), decoupling the event that triggers a
  notification from the actual provider call. This is deliberately **not** Kafka/RabbitMQ for
  v1 — Redis queues are already in the stack (`CLAUDE.md` §3) and comfortably handle a
  single-tenant-DB-per-tenant SaaS's notification volume; a real message broker is a Future
  Version extraction (see below), not a day-one requirement.
- **Dynamic template management** — a template per (category × channel × locale), with
  `{{variable}}` placeholder syntax resolved against the triggering event's payload at send
  time. Centralized so "what does our reminder email say" is one place to edit, not scattered
  across module code.
- **Retry mechanism & Dead Letter Queue** — exponential backoff (e.g. 1m/5m/30m/2h, configurable
  max attempts per channel) on provider failure; after max attempts, the message moves to a
  `msg_dead_letters` table rather than being silently dropped, visible on the WNE dashboard for
  manual review/resend.
- **Observability & tracking (v1 scope)** — every notification's lifecycle is logged as
  discrete events (`created → queued → sent → delivered/failed/bounced`) in an append-only
  `msg_delivery_events` table, with provider message ID captured for correlation. Read-receipt
  and bounce-webhook ingestion (SendGrid/Twilio status callbacks) are wired in v1 for the
  providers that support it — this is cheap once the events table exists and is a genuine
  trust/marketability feature ("did the client actually get this reminder").

## Future Version (explicitly deferred — do not build now)

- **True drag-and-drop visual canvas designer** — a real node-graph editor (react-flow-style)
  over `wrkflow_steps`/`wrkflow_transitions`. The v1 form-based builder + read-only preview
  covers the actual need (define a process without code) at a fraction of the build cost; the
  canvas is a pure UI upgrade later, additive to the same schema.
- **Full business-rules DSL** for branching conditions (v1 supports simple field
  comparisons — equals/not-equals/greater-than/contains against the trigger payload — which
  covers the overwhelming majority of real approval logic; a proper expression/rules engine
  is deferred until a tenant's actual need justifies it).
- **Message broker migration (Kafka/RabbitMQ)** — a justified extraction per `CLAUDE.md` §2
  only once notification volume genuinely outgrows Redis-backed queues across many tenants
  simultaneously (different scaling profile, possible standalone worker fleet) — not a day-one
  concern for a solo-dev-launched product.
- **Batching & digest engine** — grouping low-priority notifications into a daily/weekly
  digest. `msg_notifications.priority` and `msg_categories.digestible` flags are captured in
  the v1 schema so this is an additive scheduled job later, not a schema change; v1 sends
  every notification as it fires (acceptable at launch volume, and simpler to reason about
  and demo).
- **Advanced SLA analytics** (bottleneck heatmaps, average time-per-step reporting across
  workflow definitions) — natural fit for a future Performance/BI module or **AIInsights
  Core**'s "ask your data" pattern once there's real historical data to analyze.
- **Multi-region/multi-provider failover** for notification channels (e.g. auto-fallback from
  SendGrid to SES on sustained failure) — v1 is single-provider-per-channel per tenant,
  configurable but not automatically failed-over.
- **Workflow simulation/dry-run mode** — test a definition against sample data before
  publishing, without creating a real instance. Useful, not blocking for v1.
- **Full push notification rich features** (deep links, action buttons, notification
  grouping/threading on-device) — v1 push is a plain title/body/data-payload send.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB
> design.

## 3A. Main Dashboard

**Function / features**
- Workflow health: active instances by definition, instances breaching SLA, tasks pending
  approval (mine / my team's), recently completed/failed instances.
- Notification health: messages sent today, delivery failure rate, items in DLQ needing
  attention, quiet-hours-deferred count.
- "My work" queue — same unified pattern every downstream module's dashboard assumes exists
  (CRM's "My work," HCM's "Pending Approvals," Accounting's "My Approvals," etc. all resolve
  through this same engine).

**Layout**
- Top: summary cards — Active Instances, My Pending Tasks, SLA Breaches (24h), Notifications
  Sent Today, DLQ Items.
- Main: tabbed table — "My Tasks" | "Active Instances" | "SLA Breaches" | "DLQ / Failed
  Deliveries".
- Every row uses the shared **Status Rail** (per `DESIGN.md`): `danger` = SLA-breached /
  failed / DLQ, `warning` = due soon, `success` = completed/delivered, `info` =
  system-generated (auto-escalation, auto-retry), neutral = normal in-progress item.

**Rules / logic**
- Tenant-scoped automatically (DB-per-tenant boundary, no app-level filter needed — WNE
  follows the same rule as every other Core module).
- SLA breaches and failed deliveries surface first regardless of sort — same
  "breach-surfaces-first" convention CRM/Legal/Accounting all inherit from this module.

## 3B. Workflow Definition Builder (Entry)

**Purpose:** author a process without writing code — v1 form-based, canvas-ready schema.

- Header: `code` (unique per tenant, used by calling modules e.g. `workflow_code =
  hcm.leave_approval`), name, description, category (free lookup), status
  (`draft`/`published`/`unpublished`).
- **Step list builder**: add steps in order; each step has `type` (`approval` / `task` /
  `condition` / `parallel_split` / `parallel_join` / `webhook_call` / `wait_for_callback` /
  `notify`), a config payload (JSON — assignee rule for `approval`/`task`, condition
  expression for `condition`, URL/payload template for `webhook_call`, template reference for
  `notify`), and a position (`x`,`y` — unused by the v1 form UI but captured now so the Future
  Version canvas has no migration to do).
- **Transition list**: `from_step_id` → `to_step_id`, optional `condition_expression`
  (evaluated against the instance payload; the default/no-condition transition is the
  fallback "else" path).
- **Preview panel**: read-only node/arrow rendering of the steps + transitions just defined —
  gives the "see your process" value of a visual designer without building a full editor.
- **Publish action**: validates the graph (every step reachable, every `parallel_split` has a
  matching `parallel_join`, no orphaned steps), snapshots the current steps/transitions into an
  immutable `wrkflow_versions` row, flips status to `published`. A previously published version
  can be `unpublished` (blocks new instances from starting on it) without affecting instances
  already running on it.

**Rules / logic**
- Editing a `published` definition always edits a **new draft**, never the published version in
  place — publishing again creates version N+1. This is what guarantees in-flight instances
  never see a mid-flight-changed definition (the version-pinning requirement).
- A calling module references a definition by `code`, not by ID/version — `WorkflowService`
  resolves "the currently published version for this code" at instance-start time, exactly
  once, then pins to it.

## 3C. Workflow Instance Engine (State Management & Persistence)

**Purpose:** the durable execution core — every other engine in this module (routing, SLA,
webhooks) operates on the state this engine persists.

- `WorkflowService::start(code, subjectType, subjectId, payload): instanceId` — resolves the
  published version, creates a `wrkflow_instances` row (`status = running`) and a
  `wrkflow_instance_steps` row for the entry step (`status = pending`).
- Each step's execution is a discrete, persisted transaction: mark `in_progress` → perform the
  step's action (create a task, evaluate a condition, fire a webhook, send a notification) →
  mark `completed`/`failed` → advance to the next step(s) per the transition rules. **No step
  ever holds state only in a queue job's memory** — a queue worker crash mid-step simply means
  the step's DB row is still `in_progress`/`pending` and a recovery sweep (a scheduled job that
  looks for stuck `in_progress` steps past a grace period) can safely re-drive it, since every
  action is designed to be idempotent (checked via a step-level idempotency key).
- Instance completes (`status = completed`) when every reachable step reaches a terminal state;
  fails (`status = failed`) if a step fails without a defined failure-transition; can be
  manually `cancelled` by an authorized user (logged, never silent).

**Rules / logic**
- `wrkflow_instances.subject_type`/`subject_id` is the same polymorphic seam used by every
  other module's optional link (a Legal deed, an HCM leave request, a Purchase PO) — WNE never
  needs a foreign key into a calling module's schema.
- Fires `WorkflowInstanceStarted`, `WorkflowStepCompleted`, `WorkflowInstanceCompleted`,
  `WorkflowInstanceFailed` events — the calling module can subscribe (e.g. HCM updating
  `leave_requests.status` on approval) without WNE knowing anything about HCM.

## 3D. Routing & Branching Engine

**Purpose:** decide what happens next — the piece that turns a step list into an actual
process graph.

- **Sequential**: default — one outgoing transition, no condition.
- **Conditional branch**: a step with multiple outgoing transitions, each with a
  `condition_expression` evaluated against the instance payload (field comparisons: `=`, `!=`,
  `>`, `<`, `in`, `contains`); first matching transition wins, with a mandatory default/"else"
  transition so a branch never dead-ends silently.
- **Parallel split/join**: `parallel_split` creates N concurrent `wrkflow_instance_steps` (one
  per outgoing transition); the paired `parallel_join` step only advances once its configured
  join rule (`all` / `any`) is satisfied across the steps that fed into it.
- **Dynamic assignment**: an `approval`/`task` step's assignee resolves at runtime from one of:
  a fixed user, a role, a team, or a payload field (e.g. "the record's owner" —
  `payload.owner_id`) — covers direct-assignment and team/role-resolution patterns every
  downstream module's "My work" dashboard already relies on (same resolution logic CRM/HCM/
  Accounting/Purchase all describe as "direct assignment and team/role membership").

**Rules / logic**
- Condition expressions are stored and evaluated server-side against a **snapshot of the
  instance payload taken at start** (not a live re-query of the calling module's current data)
  — keeps branching deterministic and avoids a workflow's decision silently changing because
  source data was edited mid-flight.

## 3E. Version Control Engine

- Covered functionally in 3B (definition builder is where authoring happens); this engine is
  the enforcement layer: `WorkflowService::resolvePublishedVersion(code)` always returns the
  single currently-`published` version, and `wrkflow_instances.definition_version_id` is
  immutable once set.
- Unpublishing a version blocks new `start()` calls against that `code` (calling module should
  treat this as "workflow not configured" and surface a clear admin-facing error) but never
  touches instances already running on it.
- Full version history is retained (never deleted) for audit — same non-destructive philosophy
  every other module in this platform applies to its own historical records (DMS versions, CRM
  merge logs, Legal deed amendments).

## 3F. SLA & Escalation Engine

- `wrkflow_sla_rules`: per step (or per definition default), `sla_hours`, escalation action
  (`reassign_to_role` / `notify_manager_of_assignee` / `notify_role`), escalation target.
- A scheduled job (every few minutes) scans `wrkflow_instance_steps` where `status = pending`
  or `in_progress` past `due_at` (computed at step-start from `sla_hours`); on breach: logs a
  `wrkflow_escalation_log` row, applies the escalation action (reassignment and/or an
  additional assignee), and fires a `NotificationRequested` event through this same module's
  Notification engine — **no separate alerting mechanism**, escalation is just another
  notification trigger.
- Breached steps surface with a `danger` Status Rail on the Dashboard (3A) and in the assignee's
  Task Inbox (3H) regardless of sort, matching the "breach surfaces first" convention already
  established platform-wide.

**Rules / logic**
- Escalation is additive by default (adds an escalation-target assignee alongside the
  original) rather than silently reassigning away from the original owner, unless the rule is
  explicitly configured as `reassign` — avoids a task quietly vanishing from someone's queue
  without them knowing why.

## 3G. API & Webhook Extensibility Engine

**Outbound**
- A `webhook_call` step type: URL, HTTP method, payload template (variables resolved from the
  instance payload), and optional auth header config (stored encrypted). Dispatched as a
  queued job — reuses the **same retry/backoff + DLQ mechanism** as Notification delivery
  (§3M), rather than a second retry implementation living in the workflow engine.

**Inbound (pause-and-resume)**
- A `wait_for_callback` step type: the engine generates a signed, single-use callback token
  and exposes `POST /api/wne/callbacks/{token}`. The step's `wrkflow_instance_steps` row moves
  to `status = waiting_external`; when the callback arrives (or the configured timeout elapses,
  treated as a failure/escalation per 3F), the step resumes and the instance advances.
- This is the concrete seam other modules' specs already assume exists: a future payment
  gateway confirmation, e-signature completion, or a government portal check (Legal's tax
  clearance / BPN tracking, tracked manually today per `LEGAL_SPECS.md` §5, could later use
  this exact mechanism if an API ever becomes available) — WNE doesn't know or care what's on
  the other end of the callback.

**Rules / logic**
- Callback tokens are single-use and expire — a replayed or late callback after expiry is
  logged and rejected, not silently applied to a step that already moved on.

## 3H. My Approvals / Task Inbox

**Purpose:** the shared queue surface every other module's dashboard already assumes it can
read from — built once here, not once per module.

- List view: tasks assigned to me (direct + role/team resolution, per 3D), filterable by
  source module (via `subject_type`), due date, priority (inherited from SLA proximity).
- Task detail: what's being asked (from the step's config — e.g. "Approve leave request for
  [employee]"), a link to the source record (resolved by the calling module's own frontend,
  since WNE only stores the polymorphic pointer), and the action(s) available (`approve` /
  `reject` / a custom decision set defined on the step).
- Taking an action calls `WorkflowService::completeTask(taskId, decision, comment)`, which
  marks the `wrkflow_instance_steps` row `completed`, logs the decision, and advances the
  instance per 3D's routing rules.

**Rules / logic**
- Every decision is logged with actor, timestamp, and optional comment — feeds the audit trail
  (3-Audit) and is what makes an approval chain legally/procedurally defensible, not just a
  status flip.

---

## 3I. Multi-Channel Delivery Engine (Notification Module)

**Purpose:** send a message through whichever channel(s) apply, without the caller (workflow
step or direct module call) knowing anything about SMTP/Twilio/FCM specifics.

- `ChannelDriverInterface`: `send(NotificationMessage $message): DeliveryResult` — same
  additive-driver pattern already established by DMS's `OcrDriverInterface`, Schedule's
  `ConferenceDriverInterface`, and Inventory's `CostingStrategyInterface`; a new channel is a
  new class registered in a driver map, never a core engine change.
- v1 drivers, real implementations: `EmailDriver` (SMTP/SendGrid), `InAppDriver` (writes to
  `msg_notifications` + broadcasts over WebSocket for a live in-app badge/toast).
- v1 drivers, working but tenant-opt-in (credentials-gated): `SmsDriver` (Twilio),
  `PushDriver` (FCM for Android/web push, APNs for iOS) — the interface and delivery/retry/
  tracking plumbing is identical to Email/In-App; a tenant simply doesn't see SMS/Push options
  in the Preference Center (3J) until they configure provider credentials.
- A single logical notification (`msg_notifications` header) can fan out to multiple channels
  per the recipient's resolved preference (3J) — each channel attempt is its own
  `msg_notification_deliveries` row, tracked independently (so an email bounce doesn't hide a
  successful in-app delivery).

**Rules / logic**
- The engine never calls a provider synchronously from the triggering request — every send is
  `MessagingService::notify(...)` → event → queued job → driver. This is what keeps a
  notification spike from ever blocking the module that triggered it (a bulk HCM payroll run
  completing shouldn't wait on 200 emails sending one at a time).

## 3J. User Preference Center

- Per user, per `msg_categories` row: preferred channel(s) (multi-select — a user can want
  both email and in-app for "leave approved," just in-app for "comment mentioned you"), opt-out
  toggle (blocked entirely if the category's `is_mandatory` flag is false — mandatory
  categories like security alerts/password reset cannot be opted out of, enforced at the
  category definition level, not just a UI convention).
- **Quiet hours**: `start_time`/`end_time` in the user's tenant timezone, applied per-channel
  (a user might allow in-app anytime but silence push/SMS overnight). A notification generated
  during quiet hours for a non-urgent category is deferred to the end of the quiet-hours window
  (not dropped) — urgent/security categories bypass quiet hours entirely, same
  `is_mandatory`-style flag on the category.
- Self-service screen, same shared component library as every other settings-style form in the
  platform (`DESIGN.md`).

**Rules / logic**
- If a user has set no explicit preference for a category, the category's own
  `default_channels` apply — every category ships with a sensible default so v1 doesn't
  require every user to configure preferences before anything can be delivered.

## 3K. Message Queue & Async Processing

- **v1 implementation**: Laravel queues on Redis (already provisioned per `CLAUDE.md` §3), a
  dedicated `notifications` queue distinct from the app's general queue so a notification
  spike can't starve other background jobs (and vice versa) — the same shared queue every
  other module's spec (DMS retention, CRM SLA, HCM approvals, Accounting reminders, ...)
  already assumes exists and reuses rather than building its own.
- Event → job flow: a business event (`NotificationRequested`, or a workflow step of type
  `notify`) is translated into one `msg_notifications` row + N `msg_notification_deliveries`
  rows (one per resolved channel) + N queued `SendNotificationJob` dispatches — the queue is
  strictly the async hand-off point between "a notification needs to happen" and "a provider
  call actually happens," never a place where state only exists transiently.
- Failed jobs are retried by the queue worker (framework-level) up to the notification engine's
  own retry policy (3M), then land in the DLQ rather than the framework's generic failed-jobs
  table — keeps notification failure handling in one visible, business-facing place (the WNE
  Dashboard), not buried in infrastructure tooling only a developer would check.

## 3L. Dynamic Template Management

- `msg_templates`: `category_id`, `channel`, `locale`, subject (email/push title), body
  (HTML for email, plain text for SMS/push/in-app), variable list (documented per template for
  the admin authoring it — e.g. `{{employee_name}}`, `{{due_date}}`, `{{link}}`).
- Variable resolution happens at send time against the triggering payload (the same payload a
  workflow instance carries, or the payload passed directly by a module calling
  `MessagingService::notify(...)` outside of a workflow) — missing variables render as a
  clearly-empty placeholder in a debug/preview mode, never a silent blank in production sends
  (validated before a template can be marked active).
- Template CRUD is a simple form (per `DESIGN.md` component inventory) with a live preview pane
  showing sample-data-substituted output.

**Rules / logic**
- A category can have a template per channel per locale, but only needs the channels/locales a
  tenant actually uses — missing template combinations fail loudly at configuration time (a
  clear admin-facing warning), not silently at send time.

## 3M. Retry Mechanism & Dead Letter Queue

- Per-channel retry policy (`msg_channel_configs`): max attempts, backoff schedule (e.g.
  1 min → 5 min → 30 min → 2 hr, configurable, exponential by default).
- `msg_notification_deliveries.status` progresses `queued → sending → sent → delivered` (happy
  path) or `queued → sending → failed → retrying → failed → ... → dead_lettered` (failure
  path) — every transition is a row in `msg_delivery_events` (3O), so the exact retry history
  of any single message is fully reconstructable.
- On exhausting max attempts, the delivery moves to `msg_dead_letters` (full message + failure
  history preserved) and surfaces on the Dashboard (3A) DLQ tab — an admin can inspect the
  failure reason and manually **resend** (re-queues with a fresh attempt counter) or **discard**
  (explicit, logged action — never a silent drop).
- The exact same retry/backoff mechanism is reused by the Webhook engine (3G outbound) — one
  retry implementation in the whole module, not two.

## 3N. Batching and Digesting — **Future Version**

- `msg_categories.digestible` and `msg_notifications.priority` flags exist in the v1 schema
  (nullable/default-false) so this is a purely additive scheduled job later: group a user's
  digestible notifications since their last digest, render one digest template, send once per
  configured interval (daily/weekly). No breaking change to any table already in place.

## 3O. Observability & Tracking Engine

- `msg_delivery_events`: append-only, one row per lifecycle event
  (`created`/`queued`/`sending`/`sent`/`delivered`/`opened`/`bounced`/`failed`/`retrying`/
  `dead_lettered`) per `msg_notification_deliveries` row, with `occurred_at` and a raw
  `provider_payload` (JSON) for whatever detail the provider returned (bounce reason, message
  ID, etc.).
- **Provider status webhooks** (v1, for providers that support it — SendGrid delivery/bounce
  events, Twilio delivery-status callbacks) are ingested into the same `msg_delivery_events`
  table via a small inbound webhook endpoint per provider, keeping "did this actually arrive"
  answerable from data, not assumption.
- Surfaced on the Dashboard (3A) as delivery-rate and failure-rate summaries, and per-message
  on the notification's detail drawer as a simple timeline (same Comment/Activity Thread-style
  component pattern used elsewhere in the platform).

**Rules / logic**
- No update/delete permitted on `msg_delivery_events` at the app layer — same audit-integrity
  rule DMS applies to `access_logs`, applied here to message delivery history instead of
  document access history.

---

# 4. Storage

**Database (schema `WNE`, tenant DB — consistent with `CLAUDE.md` §7A; no `tenant_id`
column, DB-per-tenant is the isolation boundary, matching DMS/CRM/SCHEDULE and every other
Core module):**

**Master / lookup tables**
- `WNE.wrkflow_categories` — optional grouping lookup for definitions.
- `WNE.msg_categories` — tenant-editable (security, reminder, marketing, ...),
  `is_mandatory` (opt-out-blocked flag), `digestible` (Future Version flag),
  `default_channels`.
- `WNE.channel_types` — lookup: `email` / `sms` / `push` / `in_app` / `webhook`.
- `WNE.msg_channel_configs` — per tenant × channel, provider credentials (encrypted at rest),
  retry policy (max attempts, backoff schedule), enabled flag.
- `WNE.msg_templates` — `category_id`, channel, locale, subject, body, variable list.

**Workflow transaction tables** (`wrkflow_` prefix)
- `WNE.wrkflow_definitions` — header: code (unique per tenant), name, category, current status.
- `WNE.wrkflow_versions` — immutable per-publish snapshot: `definition_id`, version number,
  published_at, published_by.
- `WNE.wrkflow_steps` — `version_id`, type, config (JSON), position (`x`,`y`, for future
  canvas).
- `WNE.wrkflow_transitions` — `from_step_id`, `to_step_id`, `condition_expression` (nullable).
- `WNE.wrkflow_sla_rules` — `step_id` (or `version_id` for a definition default), `sla_hours`,
  escalation action, escalation target.
- `WNE.wrkflow_instances` — `definition_version_id` (fixed at start), `subject_type`/
  `subject_id`, status, payload snapshot (JSON), started_at, ended_at.
- `WNE.wrkflow_instance_steps` — `instance_id`, `step_id`, status, assigned_to/`assigned_role`,
  `due_at`, started_at, completed_at, decision, comment.
- `WNE.wrkflow_tasks` — denormalized "my work" view rows (or a query view) over
  `wrkflow_instance_steps` for fast inbox queries.
- `WNE.wrkflow_escalation_log` — append-only, `instance_step_id`, rule applied, escalated_to,
  escalated_at.
- `WNE.wrkflow_webhooks` — outbound webhook subscriptions/configs referenced by
  `webhook_call` steps.
- `WNE.wrkflow_callbacks` — `instance_step_id`, signed token, expires_at, consumed_at.
- `WNE.wrkflow_audit_logs` — append-only, one row per instance/step/decision event, actor,
  timestamp — same immutable pattern as `dms.access_logs`.

**Notification transaction tables** (`msg_` prefix)
- `WNE.msg_user_preferences` — `user_id`, `category_id`, preferred channels (array/JSON),
  opted_out (bool, blocked if category `is_mandatory`), quiet_hours_start, quiet_hours_end.
- `WNE.msg_notifications` — header: `category_id`, `subject_type`/`subject_id` (optional
  polymorphic source link), recipient(s), payload (JSON), priority, created_at.
- `WNE.msg_notification_deliveries` — `notification_id`, channel, status, provider_message_id,
  attempt_count, next_retry_at, sent_at, delivered_at, error_detail.
- `WNE.msg_delivery_events` — append-only lifecycle log per delivery (3O), no update/delete.
- `WNE.msg_dead_letters` — exhausted deliveries, full message + failure history, resend/discard
  action log.
- `WNE.msg_digests` — **Future Version**, stubbed empty at launch (per-user digest queue/
  batch tracking).

**Object file storage:** none owned by WNE directly. If a workflow step or notification ever
needs to attach a file (e.g. an approval decision with a supporting document), it attaches via
**DMS**'s `DocumentService::attach()` with `subject_type = 'wne.wrkflow_instances'` etc., same
reuse discipline as every other module — WNE does not implement parallel file storage.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, first of the four foundational modules in the build
order (`CLAUDE.md` §5), at `app/Modules/WNE/` — same shape as every later Core module
(`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`, `Routes/`). No
microservice extraction at MVP: workflow execution and notification dispatch are CRUD +
queued-job orchestration, not a different-runtime or independent-scaling workload per
`CLAUDE.md` §2's extraction criteria. The two pieces flagged as **justified future
extractions**, per that same rule, are (a) a real message broker (Kafka/RabbitMQ) if
notification volume ever outgrows Redis-backed queues across the tenant base, and (b) a
dedicated notification-worker fleet if provider call volume needs independent scaling from the
main app — neither is a day-one concern.

- **Internal facade** (preferred, same-process) — `WorkflowService::start(...)`,
  `::completeTask(...)`, `::cancel(...)`; `MessagingService::notify(...)`,
  `::getPreferences(...)`, `::updatePreferences(...)` — the integration point every other
  Core/Vertical module calls into, exactly as every later module's spec already assumes.
- **Internal event bus** — publishes `WorkflowInstanceStarted`, `WorkflowStepCompleted`,
  `WorkflowInstanceCompleted`, `WorkflowInstanceFailed`, `NotificationSent`,
  `NotificationFailed`, `NotificationDeadLettered`; consumes `WorkflowRequested` and
  `NotificationRequested` from any calling module — this is the exact seam every later spec
  (DMS §3F retention, CRM §3E SLA, HCM §3F leave approval, Accounting §3C posting approval,
  Purchase §3K exceptions, Sales §3E quote approval, Legal §3C deed signing) references as
  "fires into WNE" — those events are the calling contract this module must honor precisely.
- **Cross-schema reads, never writes**: WNE resolves users/roles/teams from the platform's
  auth tables and, where relevant, `CRM.partners` (e.g. notifying a partner's contact point) —
  always a read via the owning module's facade, never a direct cross-schema FK into a
  Vertical module (WNE is Core; Core never depends on Vertical, and here specifically WNE
  should not even assume which other Core modules are installed — see feature-flag note
  below).
- **Feature-flag independence**: WNE must not assume any other module is installed — it is the
  *first* module a tenant might have, with nothing else present. Every integration point (CRM
  partner resolution, DMS attachment) is optional and guarded, mirroring the "must not throw if
  X is absent" posture every later module's spec explicitly adopts *toward WNE* — WNE itself
  must adopt the same posture toward everything else, since nothing else is guaranteed to exist
  first.

**Idempotency & fault tolerance (the core design decision for the Workflow Engine):** every
step execution and every notification send is designed to be safely re-triggerable. A step
carries an idempotency key derived from `(instance_id, step_id, attempt)`; a queue job that
re-runs after a crash checks persisted state first and no-ops if the step is already
`completed`. This is what satisfies the "fault-tolerant execution if the system restarts"
requirement without needing a more exotic durable-execution framework — plain DB-persisted
state + idempotent job handlers is sufficient at this scale and keeps the whole engine
understandable by one solo dev.

**Why Redis queues, not Kafka/RabbitMQ, for v1:** Redis is already provisioned in the stack
(`CLAUDE.md` §3) for cache and queue use. A dedicated `notifications` queue on the existing
Redis instance comfortably handles realistic single-tenant-DB-per-tenant SaaS volume (each
tenant's notification traffic is naturally isolated by database, so there's no cross-tenant
noisy-neighbor problem to solve with a heavier broker). Introducing Kafka/RabbitMQ now would
be exactly the kind of premature "clean architecture" extraction `CLAUDE.md` §2 explicitly
warns against — real operational cost (a new service to run, monitor, and understand) with no
present justification. Revisit only if a specific tenant's volume or a genuine need for
cross-service durability/replay demands it.

**Version pinning implementation detail:** `wrkflow_instances.definition_version_id` is set
once, at `start()`, by resolving `wrkflow_definitions` → its currently-`published`
`wrkflow_versions` row, and is never re-resolved for the lifetime of that instance — even if
the definition is republished (new version) while the instance is still running. This is a
single `NOT NULL`, immutable-after-insert column; enforcing it is a service-layer discipline
(never an update statement touches this column), not a DB trigger, keeping it simple and
explicit for future-you re-reading the code.

**Quiet hours + mandatory-category interaction:** the Preference Center (3J) check runs
*before* channel dispatch, not before notification creation — a `msg_notifications` row is
always created immediately (so nothing is lost/delayed at the source-of-truth level), but the
per-channel `msg_notification_deliveries` row for a non-mandatory category during quiet hours
is created with `status = deferred` and a `next_retry_at` set to the end of the quiet-hours
window, reusing the same retry-scheduling mechanism (3M) rather than inventing a second
deferred-send code path.

**MVP scope boundary (explicit, so nothing below blocks a fast first ship):**
- Visual canvas designer, full rules-engine DSL, Kafka/RabbitMQ, digest batching, and advanced
  SLA analytics are all Future Version (§2) with schema-level placeholders already reserved
  (`digestible`, `priority`, step `position` x/y) so none of them require a breaking migration
  later — same "additive migration only" discipline DMS applies to `extracted_text`/`pgvector`.
- SMS/Push drivers ship as real, working `ChannelDriverInterface` implementations in v1 (not
  deferred) precisely because the *pattern cost* of adding a channel later is near-zero once
  Email/In-App prove the interface out — but they are credential-gated, so a tenant not ready
  to pay for Twilio/FCM simply never sees them exposed.

**Queues:** Workflow step execution and notification dispatch are both async via the shared
`notifications` queue (Redis-backed) — this is the same queue every later module's spec
(DMS, CRM, HCM, Accounting, Purchase, Sales) explicitly reuses rather than building its own,
which is only possible because WNE establishes it first, here, as the platform's one
notification-adjacent queue.

**Suggested build order for Claude Code:** 3C (workflow instance engine — state
persistence is the foundation everything else, including 3D/3F/3G, depends on) → 3B
(definition builder, form-based + preview) → 3D (routing/branching, wired into 3C) → 3E
(version pinning — mostly enforcement logic once 3B/3C exist) → 3H (My Approvals inbox, cheap
once 3C exists, high value — this is what every other module's dashboard will link to) → 3I
(multi-channel delivery — Email + In-App drivers first) → 3L (templates, needed before any
real notification content can render) → 3K (queue wiring) → 3J (preference center) → 3M
(retry + DLQ) → 3O (observability/tracking) → 3F (SLA & escalation, now that both workflow
tasks and notifications exist to escalate through) → 3G (webhook extensibility, reuses 3M's
retry mechanism) → **ship — this is the point every other Core module's build can begin
against a real WNE** → revisit Future Version items (canvas designer, digest batching, broker
migration) once there's real multi-tenant usage to justify them.

**Marketability notes**
- WNE is infrastructure, not a demo screen on its own — its marketability is indirect: it's
  what makes every other module's "Status Rail," "My Approvals," and "you'll be notified"
  promises actually true. Sales conversations should lead with the *downstream* modules'
  demos, not WNE itself, but WNE's correctness is what keeps those demos credible.
- Standalone sellability (generic approval-chain + notification automation, no other module
  required) opens a lightweight second go-to-market motion — a small business wanting "just"
  automated approvals/reminders without buying a full ERP vertical, same low-cost validation
  lever already used for DMS/Schedule/Inventory's standalone stories.
- Delivery observability (read receipts, bounce tracking, full retry history) is a concrete
  "did this actually work" trust point for a conservative legal-buyer audience — worth
  surfacing in a demo ("here's proof your client got the reminder"), same posture DMS's audit
  trail and Legal's protocol ledger already use as selling points.
