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

## 3A. Main Dashboard

**Function / features**
- At-a-glance view of: pending approvals (assigned to me / assigned to my role), workflows
  I've initiated and their current state, notification delivery health (sent / failed / queued
  today), recent failures needing attention.
- Quick actions: Approve / Reject / Delegate directly from the dashboard list.
- Filter by module (Purchasing, HR, ...), by workflow type, by date range, by status.

**Layout**
- Top: 4 summary cards — Pending My Action, Total In-Flight Workflows, Notifications Sent
  (24h), Notifications Failed (24h).
- Left/main: tabbed table — "My Approvals" | "Workflows I Started" | "Notification Log".
- Row click opens a drawer/modal showing full workflow history (timeline) or full
  notification payload + delivery attempts.

**Rules / logic**
- "My Approvals" resolves via current user's direct assignment **and** role/group
  membership at the current pending step.
- Dashboard queries are tenant-scoped automatically (global tenant scope on all MSG tables).
- Failed notifications older than configurable retry-window surface with a "Retry" button
  visible only to admins.

## 3B. Workflow Configuration

**Purpose:** define reusable approval / state-machine templates that any module can bind to.

- **Workflow Definition**
  - `code`, `name`, `module` (owning module, e.g. `purchasing.po`), `description`, `is_active`,
    `version` (definitions are versioned; in-flight instances keep the version they started on).
- **States**
  - Ordered or graph-based list of states (e.g. Draft → Submitted → Manager Review →
    Finance Review → Approved / Rejected).
  - Each state has: `is_initial`, `is_final`, `sla_hours` (optional timeout).
- **Transitions**
  - `from_state`, `to_state`, `action` (Approve, Reject, Request Revision, Escalate).
  - **Conditions** (rule builder: field/operator/value, e.g. `amount > 10,000,000` routes to an
    extra Finance Review state) — supports AND/OR groups.
  - **Approvers** per transition: fixed user(s), role, dynamic (e.g. "requester's direct
    manager", resolved from HR module), or approval-group with `any-one` / `all-must-approve`
    / `majority` quorum rules.
  - **On-transition actions**: fire a Notification event, call a webhook, update a field back
    on the source record (via callback/event the owning module listens to).
- **Escalation / Timeout**
  - Per-state SLA; on breach: auto-escalate to next approver, notify a supervisor, or
    auto-reject — configurable per workflow.
- **Delegation**
  - Users can delegate their approval authority to another user for a date range (out-of-office).

## 3C. Messaging Control

**Purpose:** configure *what* gets sent, *how*, and *to whom*.

- **Event Catalog** — registry of event codes any module can publish
  (e.g. `po.created`, `po.approved`, `workflow.step_pending`, `workflow.escalated`).
- **Templates**
  - Per event code × channel × locale × tenant: subject (email), body (supports merge-fields
    like `{{requester_name}}`, `{{amount}}`, `{{approval_link}}`), and channel-specific
    formatting (HTML for email, plain/short for SMS/WhatsApp).
  - Template versioning + preview/test-send.
- **Channel Configuration**
  - Per tenant: which provider is active per channel (e.g. SMTP/SES for email, Twilio/Vonage
    for SMS, WhatsApp Business API, FCM/OneSignal for push), credentials, rate limits,
    sender identity.
- **Routing Rules**
  - Which channel(s) fire for which event (e.g. `workflow.step_pending` → in-app + email;
    `workflow.escalated` → in-app + SMS).
  - Recipient resolution: static user/role, dynamic (workflow approver, record owner), plus
    per-user channel opt-out preferences (respect "do not SMS me" settings).
- **Rate Limiting / Digest**
  - Optional batching — e.g. collapse multiple in-app notifications into a digest instead of
    spamming.

## 3D. Workflow Engine

**Purpose:** the runtime that actually executes 3B's definitions.

- **Trigger intake** — listens for `WorkflowRequested` events published by other modules;
  payload includes `workflow_code`, `subject_type`, `subject_id`, `initiator_id`, `context`
  (arbitrary JSON used by condition rules and templates).
- **State machine execution**
  - Creates a `workflow_instance`, resolves the initial state, evaluates transition
    conditions against `context`, resolves approvers, creates `workflow_task` record(s) for
    each pending approver/group.
  - On approver action (Approve/Reject/Delegate), records `workflow_task_action`, re-evaluates
    quorum rules, and either advances to next state or finalizes.
  - Every state change fires an internal `WorkflowStateChanged` event → Messaging Control
    picks it up if a routing rule matches → queues Notification dispatch.
- **Callback to owning module**
  - On final state (Approved/Rejected), fires a module-specific callback/event so e.g.
    Purchasing can flip its PO status — MSG never writes directly into another module's
    tables, preserving modular boundaries.
- **Notification dispatch**
  - Queue job per (recipient × channel), calls the appropriate `ChannelDriverInterface`
    implementation, logs attempt + provider response, retries with exponential backoff
    (configurable max attempts), moves to dead-letter log on exhaustion.

---

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
