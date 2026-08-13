# Schedule Module
## Calendar & Scheduling Engine — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Almost every module in the ERP eventually needs to put something on a calendar: a follow-up
task, a client meeting, a court date, a delivery slot, a shift, a room booking. Left to each
module, this gets solved independently — duplicate date-picker logic, no shared notion of "is
this person/room free right now," no single calendar a user can look at across all their work,
and recurrence/reminders re-invented per module (or skipped entirely because they're fiddly).

**Client requirements (from your feature list):**
- Tasks (to-dos with due dates, no attendee required) and Events (time-blocked, usually
  multi-attendee) as first-class, distinct-but-unified concepts.
- Planning & Scheduling **on Resources** — not just people: meeting rooms, equipment, vehicles,
  shared staff — so it works for both office scheduling and field/ops scheduling.
- Availability checks — don't let two things get booked onto the same resource/person at an
  overlapping time.
- Recurrence — "every Monday," "first of every month," etc., industry-standard (RRULE) so it's
  portable and calendar-app-familiar.
- Conference integration (audio/video) — generate/attach a join link (Zoom/Meet/Teams/custom)
  when an event needs one, without hardcoding one vendor.
- Mobile support — usable on a phone; calendar data should also be consumable outside the app
  (subscribe from Apple/Google Calendar).
- Must work **standalone** — sellable/usable even for a tenant that hasn't bought Workflows or
  a vertical module yet, but integrates cleanly when WNE (Workflow & Notification Engine) or a
  vertical module (e.g. Legal case deadlines) is present.

**Business context:** Scheduler is listed in `CLAUDE.md` §5 as one of the four **Core** modules
built before the first vertical (Legal). Legal will lean on this heavily (court dates, filing
deadlines, client meetings), so the data model needs a clean way for other modules to attach
their own record to a calendar item without Schedule knowing anything about Legal — same
decoupling pattern already established by WNE (`subject_type` / `subject_id` polymorphic link,
events instead of direct calls).

# 2. Goals

> Designated features solving the Backgrounds above. **MVP-first** — anything not needed to
> ship a usable, sellable Scheduler is pushed to Future Version below.

## In scope for v1 (quick implementation)
- **Unified calendar item model** — Tasks and Events share one backbone table (start/end,
  owner, status) with a `type` discriminator, so one calendar view renders both. Keeps the
  implementation small instead of two parallel systems.
- **Resource booking** — bookable Resources (room / equipment / vehicle / staff-as-resource),
  linked to calendar items via a booking pivot, so a resource's schedule is just "all bookings
  where resource_id = X."
- **Availability check** — a single service call: *"is Resource X (or User X) free between
  T1–T2?"* — checks for overlapping bookings. Optional simple weekly working-hours table per
  resource for basic "outside business hours" checks.
- **Recurrence (RRULE-based)** — store the industry-standard iCalendar RRULE string
  (`FREQ=WEEKLY;BYDAY=MO;COUNT=10`, etc.) on the calendar item; expand occurrences on read
  (not pre-materialized), with a small exceptions table for "skip this one" / "moved this one."
  This is the fastest path to correct recurrence and stays compatible with ICS export/import.
- **Conference integration** — pluggable `ConferenceDriverInterface` (mirrors WNE's
  `ChannelDriverInterface` pattern you already use), storing provider + join URL + meeting
  metadata on the event. v1 driver: manual/custom link entry + one real provider (Zoom **or**
  Google Meet, whichever you have API access to first). Additional providers are additive later.
- **Mobile support (v1 = responsive + subscribe, not a native app)**:
  - Responsive calendar views (day/week/month/agenda) via the existing Vue/Inertia + Tailwind
    design system (`DESIGN.md`) — no separate mobile codebase needed yet.
  - A per-user/per-resource **ICS feed URL** (signed, UUID token) so people can subscribe from
    their phone's native calendar app — cheap to build, high perceived value, genuinely
    sellable as "your schedule, in your own calendar app."
- **Decoupled integration hooks** — Schedule publishes internal events
  (`schedule.item_created`, `schedule.item_due_soon`, `schedule.item_cancelled`) that WNE (if
  installed/enabled for the tenant) can route to notifications, exactly like Purchasing/HR do
  in the WNE spec. Schedule itself never calls a mail/SMS provider directly.
- **Standalone-safe**: if WNE is not enabled for a tenant, Schedule still functions fully for
  tasks/events/resources/availability — it just has no outbound notifications (or falls back to
  a minimal built-in "due today" in-app badge, no external channels).

## Future Version (explicitly deferred — do not build now)
- Resource conflict **auto-resolution** / waitlisting / alternate-resource suggestions.
- Resource **pools/capacity** (e.g. "any 1 of 5 laptops," not a specific asset).
- Calendar **sharing/delegation** (view/manage someone else's calendar).
- **Two-way external sync** with Google/Outlook (v1 ICS is one-way, read-only subscribe).
- Per-attendee **timezone display** (v1 stores/operates in a single tenant timezone + UTC;
  proper per-attendee timezone rendering is a nice-to-have, not MVP).
- SLA/escalation on overdue Tasks (that's WNE Workflow territory if it's ever needed — don't
  duplicate workflow logic here).
- Drag-and-drop Gantt/resource-utilization view.
- Native mobile app / push notifications (push channel already exists at the WNE layer whenever
  you're ready — Schedule doesn't need its own).
- Embeddable public booking widget (e.g. "book a consultation" for external clients).

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Calendar Dashboard

**Function / features**
- Day / Week / Month / Agenda views, toggle-able, showing Tasks and Events together.
- Filter by: my items / a specific resource / a specific module (e.g. only Legal-linked items).
- Quick-create: click a time slot → inline mini-form (title, type, time, resource) without
  leaving the calendar.
- Status Rail (per `DESIGN.md`) on every item: `danger` = overdue task / conflicting booking,
  `warning` = due soon, `success` = completed, `info` = system-generated (e.g. auto-recurring
  instance), neutral border = plain scheduled item.

**Layout**
- Top bar: view switcher (Day/Week/Month/Agenda), date navigator, "+ New" button, resource
  filter dropdown.
- Main: calendar grid (per selected view) or agenda list.
- Side panel (on item click): drawer showing full detail, attendees, resource(s) booked,
  conference link if any, recurrence summary, and quick actions (Edit / Cancel / Mark Done).

**Rules / logic**
- Calendar queries are scoped to the current tenant DB automatically (DB-per-tenant — no
  app-level tenant filter needed, unlike WNE's `tenant_id` scope).
- Recurring items are expanded server-side into virtual occurrences for the visible date range
  only (never materialize the full recurrence series into rows).
- Availability conflicts (double-booked resource) surface as a `danger` Status Rail directly on
  the calendar, not just in a hidden validation message.

## 3B. Task Management (Form)

- Fields: `title`, `description`, `due_at`, `priority` (low/normal/high), `status`
  (open/in_progress/done/cancelled), `owner_id`, `subject_type`/`subject_id` (optional
  polymorphic link back to the record that spawned it, e.g. a Legal case), `recurrence_rule`
  (optional).
- No attendees/resources required — a Task is single-owner by default (can still name other
  users as watchers in v1 via a simple attendee row with role = `watcher`, optional).
- "Mark Done" is a one-click action from both the dashboard and the item drawer.

## 3C. Event Management (Form)

- Fields: `title`, `description`, `start_at`, `end_at`, `all_day`, `location` (free text, for
  physical events), `status` (scheduled/cancelled), `owner_id`,
  `subject_type`/`subject_id` (optional), `recurrence_rule` (optional).
- **Attendees**: add internal users (and later, external emails — v1 supports internal users
  only, to keep scope small; external invitee emails is a fast follow, not blocking).
- **Resources**: attach one or more bookable resources (e.g. "Conference Room A" + "Projector").
- **Conference**: optional toggle "Add video/audio link" → picks a configured provider, creates
  the meeting via that provider's driver (or lets the user paste a manual link).

## 3D. Resource Management (Form)

- `resource_types` (master): Room, Equipment, Vehicle, Staff — extensible list, not hardcoded
  enum, so a tenant can add their own type without a code change.
- `resources`: name, type, location/notes, is_active, optional capacity (int, informational
  only in v1 — not enforced/pooled, see Future Version).
- Optional simple **working hours** per resource (day-of-week + start/end time) to support the
  availability check below. If not set, resource is treated as available 24/7.

## 3E. Availability Check (Engine)

**Purpose:** the one reusable service every other form calls before confirming a booking.

- `AvailabilityService::isFree(resourceOrUserId, startAt, endAt): bool`
- `AvailabilityService::findConflicts(resourceOrUserId, startAt, endAt): array`
- Logic: overlap check (`existing.start < new.end AND existing.end > new.start`) against
  active (non-cancelled) bookings for that resource/user, plus — if working hours are defined —
  a check that the requested window falls inside them.
- Called synchronously on save (blocks a conflicting save with a clear error, per `DESIGN.md`
  voice guidance: *"Conference Room A is already booked 2:00–3:00 PM. Choose another time or
  resource."*) — no async/queue needed for this, it's a fast DB query.

## 3F. Recurrence Engine

**Purpose:** expand a recurrence rule into concrete occurrences for a given date range, and
handle "this occurrence only" edits/cancellations.

- Store one `recurrence_rule` (RRULE string, RFC 5545 subset: `FREQ`, `INTERVAL`, `BYDAY`,
  `COUNT` or `UNTIL`) on the parent calendar item.
- Use an existing, battle-tested RRULE library (e.g. `simshaun/recurr` for PHP) rather than
  hand-rolling recurrence math — this is exactly the kind of "don't reinvent it" case that keeps
  v1 fast.
- `recurrence_exceptions` table: `(calendar_item_id, original_occurrence_date, action:
  skipped|moved|modified, override_start_at, override_end_at)` — lets a user delete or reschedule
  a single instance without breaking the series.
- Availability checks run **per expanded occurrence**, not just once on the parent — a weekly
  recurring booking must not silently conflict on week 3.

## 3G. Conference Integration (Engine)

**Purpose:** attach a join link to an Event without Schedule hardcoding a vendor.

- `ConferenceDriverInterface`: `createMeeting(event): ConferenceLink`,
  `cancelMeeting(conferenceLink): void` — same additive-driver pattern as WNE's
  `ChannelDriverInterface`, so a new provider is a new class, not a core change.
- v1 drivers: `ManualLinkDriver` (user pastes any URL — zero integration cost, ships day one)
  and **one** real provider driver (Zoom or Google Meet — pick whichever has the simpler OAuth
  setup for a solo dev; recommend starting here since it's the highest perceived value for the
  least code).
- Stored per event: provider code, join URL, external meeting ID (for future cancel/update
  calls), dial-in info if applicable (text field, not structured — not worth modeling in v1).

# 4. Storage

> List tables and object files storage used in this module.

**Schema:** `SCHEDULE` (per `CLAUDE.md` §7 database structure — one schema inside each
tenant's database; no `tenant_id` column needed since the database itself is the isolation
boundary).

**Master tables** (single word)
- `resource_types`
- `resources`
- `conference_providers`

**Transaction tables** (`sched_` + level, matches WNE's `wrkflow_`/`msg_` convention)
- `sched_items` — unified Task/Event header (`type` column: `task` | `event`)
- `sched_bookings` — pivot: which resource(s) are booked to which `sched_items` row
- `sched_attendees` — which users are on an item (owner/attendee/watcher role)
- `sched_recurrence_exceptions` — per-occurrence overrides for recurring items
- `sched_conference_links` — conference metadata for events that have one
- `sched_working_hours` — optional per-resource weekly availability window
- `sched_calendar_feeds` — signed UUID tokens for ICS subscription URLs (per user or per
  resource)

**Object files:** none required for v1 (no attachments on calendar items yet). If task/event
attachments are wanted later, they'd live under the existing per-tenant R2 structure as
`tenant_xxx/SCHEDULE/...`, consistent with `CLAUDE.md` §7B.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Modular monolith module at `app/Modules/Schedule/`, same shape as
every other Core module (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`,
`Enums/`, `Routes/`). No microservice extraction here — Schedule is plain CRUD + a couple of
calculation-heavy services (availability, recurrence expansion), none of which need a different
runtime or independent scaling per `CLAUDE.md` §2's extraction criteria.

**Cross-module integration (decoupled, event-driven — same seam as WNE):**
- Other modules attach to a calendar item via `subject_type` / `subject_id` (polymorphic),
  never a hard foreign key into Legal/CRM/etc. — Schedule stays vertical-agnostic.
- Schedule publishes internal events (`schedule.item_created`, `schedule.item_due_soon`,
  `schedule.item_cancelled`); WNE listens and applies its own routing rules **if the tenant has
  WNE enabled**. Schedule has zero compile-time dependency on WNE classes.
- Feature-flag aware: `SCHEDULE` and `NOTIFICATIONS` (WNE) can each be toggled per tenant/plan
  independently, per `CLAUDE.md` §4's plan/feature-flagging note — Schedule must not throw if
  WNE is simply absent for a tenant.

**Recurrence:** use `simshaun/recurr` (or equivalent maintained RRULE library) — don't hand-roll
RFC 5545 parsing. Expand occurrences on read for the visible date range; never pre-generate rows
for the full series.

**ICS export:** generate on-the-fly from `sched_items` + expanded recurrences using a small ICS
writer (e.g. `spatie/icalendar-generator`); serve at a signed URL keyed by the UUID token in
`sched_calendar_feeds`, so it can be revoked without touching user auth.

**Queues:** Availability checks and CRUD are synchronous (fast, user-facing). Only the
"publish `schedule.*` event → WNE picks it up → sends notification" leg is async, and that
queue already exists on the WNE side (`notifications` queue) — Schedule doesn't need its own
queue for v1.

**IDs:** `BIGSERIAL` for all internal PKs/FKs per `CLAUDE.md` §7. `sched_calendar_feeds.token`
is a UUID (external-facing, appears in a URL — must not be a guessable sequential ID).

**Extensibility:** new conference provider = new class implementing
`ConferenceDriverInterface`, registered in a driver map — no core engine changes, mirrors the
WNE channel-driver pattern you're already using, so the two modules stay conceptually
consistent for future-you re-reading the code.
