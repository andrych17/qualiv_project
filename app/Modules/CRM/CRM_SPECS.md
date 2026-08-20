# CRM Module
## Core Shared Module — Partner Registry, Leads, After Sales Service, Helpdesk

# 1. Backgrounds

> Pain point and business value.

Every vertical (Legal today; Property, and others later) transacts with people and
organizations — clients, tenants, vendors, referral partners, opposing counsel, service
providers. Left to each vertical module, "who this person is" gets solved independently:

- Each vertical stores its own flavor of contact record — Legal's "Client" table looks
  nothing like Property's "Tenant" table, even though both are just a name, an address, a
  phone number, and a set of documents/history.
- The same real-world person or company ends up duplicated across modules with no way to see
  it's the same entity — no 360° view, no single place to update a phone number, no way to
  flag "this vendor is also a client" or "this lead became a client six months ago."
- Post-transaction relationship management (support requests, service follow-ups, general
  inquiries) has nowhere to live — it's not a Sales Order, not a Legal Case, but it's still
  work tied to a partner that needs to be tracked, assigned, and resolved.
- Lead capture (pre-partner interest) has no home either, so sales/intake activity before a
  real transaction exists is either lost or bolted onto whichever vertical got there first.

**Client requirements:**
- One unified Partner registry, reusable by every vertical — Sales, Legal, Property, and
  whatever comes next — without any vertical owning or duplicating contact data.
- Distinguish **individual people** from **organizations**, and represent that a person can
  work for / represent an organization.
- Represent that a single partner can hold **multiple roles at once** (a company can be both a
  Vendor and a Client), and role vocabulary must be **tenant-configurable** — a law firm calls
  it "Client," a property manager calls it "Tenant" or "Owner" — without a core migration per
  vertical.
- Capture **Leads** before they're real partners, with a qualification pipeline, and convert a
  qualified lead into a Partner without re-entering data.
- Track **After Sales Service** cases and **Helpdesk** tickets against a partner, optionally
  linked back to whatever vertical record triggered them (a Sales Order, a Legal Case), without
  CRM ever depending on those modules.
- Support **deduplication/merge** — CRM data quality degrades constantly (typos, duplicate
  entry across intake channels); there must be a safe way to merge two partner records without
  losing history.
- Multi-tenant aware, same as every other Core module: tenant-scoped, and open to per-tenant
  custom fields (via the `CUSTOMFIELDS` schema) without core migrations.
- Decoupled from every vertical: CRM publishes events (`PartnerCreated`, `LeadConverted`,
  `TicketCreated`, ...) and exposes a facade; it never reaches into Legal/Sales/Property
  tables, and they only ever reach *into* CRM (never the reverse — Core has zero knowledge of
  Vertical, per project convention).

# 2. Goals

> Designated features solving the Backgrounds above.

- **Unified Partner registry.** A single `partners` table represents both **Companies**
  (organizations) and **Contacts** (individuals), with a `parent_partner_id` link so a Contact
  can be "employed by / represents" a Company. Presented as two distinct Forms (3B, 3C) for a
  clean UX, backed by one schema — no duplicated fields, one place to dedupe, one audit trail.
- **Tenant-configurable Role system.** Roles (Customer, Vendor, Client, Employee, Referral,
  Other, ...) live in a tenant-editable lookup table, assigned many-to-many to partners. This
  is what lets the *same* core CRM be sold under different vocabulary per vertical without
  touching code.
- **Lead pipeline.** Capture inbound interest (source, owner, stage), qualify, and one-click
  convert to a Partner (+ initial Role) — no re-typing, no orphaned lead data.
- **After Sales Service.** Case tracking for post-transaction service work, optionally pointing
  back at the originating vertical record via a loose `subject_type` / `subject_id` reference
  (mirrors the pattern WNE uses) — CRM never foreign-keys into a vertical schema.
- **Helpdesk.** General-purpose ticketing (support, inquiries, complaints) — not necessarily
  tied to any prior transaction. Shares ticket categories, priority, and SLA machinery with
  After Sales Service, but kept as its own engine since its lifecycle and audience (any partner,
  even a lead) differs.
- **Deduplication / Merge tool.** Detect likely-duplicate partners (name/email/phone
  similarity) and merge safely, with a reversible audit log.
- **Multi-address / multi-contact-point support.** Partners can carry more than one address
  (billing, shipping, office) and more than one phone/email, each flagged with a type and a
  primary flag.
- **WNE integration.** Publishes events other modules — including WNE itself — can subscribe
  to: lead assignment notifications, ticket SLA-breach escalation via Workflow, ticket-status
  notifications via Messaging. CRM does not implement notification delivery itself; it emits
  events and lets WNE's routing rules decide what fires.
- **Custom fields.** Every entity here (`partners`, `leads`, `hd_tickets`, `svc_cases`) is
  extensible per tenant via `CUSTOMFIELDS`, so a vertical-specific "extra field" a tenant wants
  doesn't require a core migration.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard

**Function / features**
- At-a-glance CRM health: total active partners by role, open leads by stage, open tickets by
  SLA state (on-track / due soon / breached), open After Sales cases.
- "My work" surface: leads assigned to me, tickets assigned to me, cases assigned to me —
  regardless of which engine they came from, unified in one queue.
- Quick actions from the dashboard: assign, change stage/status, open full record.

**Layout**
- Top: 4 summary cards — Open Leads, Open Tickets, Open Service Cases, Partners Added (30d).
- Main: tabbed table — "My Leads" | "My Tickets" | "My Service Cases" | "Recent Partners".
- Each row uses the shared **Status Rail** (per DESIGN.md) colored by state — this is the same
  visual motif used in Scheduler/Workflows/Notifications, so CRM reads as part of one platform.
- Row click opens a drawer with full record + activity timeline.

**Rules / logic**
- All queries tenant-scoped automatically (global tenant scope on every CRM table).
- "My work" resolves via direct assignment **and** team/role membership, same resolution
  pattern as WNE's "My Approvals."
- SLA-breached tickets/cases surface first regardless of sort, with a persistent visual flag.

## 3B. Contacts

**Purpose:** manage individual people — the "who," whether or not they're tied to a Company.

- Fields: name, individual-specific fields (title/position if employed by a Company), primary
  address, primary contact point (email/phone), `parent_partner_id` (nullable — which Company,
  if any, they represent), roles (many, via `partner_roles`), tags, owner (internal user
  responsible for the relationship), source (how they entered CRM — manual, lead conversion,
  import).
- List view: filterable/sortable data table (shared component), Status Rail reflects
  "active/inactive" or a role-driven color if only one role is present.
- Detail view: tabs — Overview, Addresses, Contact Points, Roles & Tags, Related Leads,
  Related Tickets/Cases, Activity Timeline, Custom Fields.
- A Contact with no `parent_partner_id` is a standalone individual (e.g. a sole-practitioner
  vendor, or an individual Legal client) — Companies are not required.

**Rules / logic**
- Deleting/deactivating a Contact never cascades into vertical modules — it only marks the
  partner inactive; whether a vertical still shows historical records referencing it is that
  vertical's call.
- Changing `parent_partner_id` is logged (who moved this contact to a different company, when).

## 3C. Companies

**Purpose:** manage organizations — the umbrella a Contact can belong to.

- Same underlying `partners` table as Contacts, filtered to `type = organization`. Fields:
  legal name, trade name, registration/tax ID, industry, primary address, roles, tags, owner.
- Detail view adds a **Contacts** tab: every Contact whose `parent_partner_id` points here,
  with primary/decision-maker flagging.
- Same Related Leads / Related Tickets/Cases / Activity Timeline / Custom Fields tabs as
  Contacts, since both are Partners under the hood.

**Rules / logic**
- A Company can itself have a `parent_partner_id` (subsidiary of another Company) — same
  self-referencing mechanism, reused rather than adding a second relationship concept.
- Merging two Companies re-parents all their Contacts to the surviving record (see 3G).

## 3D. Leads

**Purpose:** track pre-partner interest through a qualification pipeline; convert to a real
Partner once qualified — without re-entering data.

- **Lead record:** name/company (free text until conversion), source (`lead_sources` lookup —
  referral, web, event, cold outreach, ...), stage (New → Contacted → Qualified → Converted /
  Disqualified), owner, estimated value (optional, free-form — no assumption of currency/deal
  size logic since that belongs to Sales, not CRM), next action date, notes.
- **Board view:** Kanban by stage (drag to advance), using the Status Rail motif per stage
  color. **List view:** sortable table, same shared component as everywhere else.
- **Conversion:** one action — "Convert to Partner" — creates (or links to, if a dedupe match
  is found) a `partners` record, assigns the chosen initial Role, copies lead notes into the
  new partner's Activity Timeline, and marks the lead `Converted` with a link to the resulting
  partner. This is the point where a Lead stops being CRM's problem and becomes a Partner the
  verticals can transact with.
- **Disqualification:** requires a reason code (lost to competitor, no budget, not a fit, ...)
  — feeds a simple loss-reason report, useful even at MVP for demoing pipeline health.

**Rules / logic**
- A Lead is *not* a Partner and has no roles — it cannot be referenced by any vertical
  transaction. This boundary is what keeps "pipeline noise" out of real partner data.
- Optional: large/high-value lead qualification can route through a WNE Workflow
  (`WorkflowRequested` with `workflow_code = crm.lead_qualification`) if a tenant wants
  manager sign-off before conversion — CRM doesn't implement approval logic itself, it just
  triggers WNE like any other module would.

## 3E. After Sales Service

**Purpose:** track service work that follows a completed transaction in another module
(a Legal matter needing a follow-up filing, a Property unit needing post-move-in service, a
future Sales order needing warranty support) — without CRM knowing what that transaction is.

- Fields: partner (required), subject (short description), category (`ticket_categories`
  lookup, shared with Helpdesk), priority, status (Open → In Progress → Waiting on Partner →
  Resolved → Closed), assigned agent/team, SLA due date, `subject_type` + `subject_id`
  (nullable — e.g. `subject_type = 'legal.case'`, `subject_id = 4821`; purely informational,
  **not** a foreign key, since CRM cannot reach into another module's schema).
- Detail view: case header + threaded activity log (notes, status changes, attachments),
  same Comment/Activity Thread component used by Workflows and Legal case notes per DESIGN.md.
- List view: filterable by status/priority/SLA state/assigned agent, Status Rail colored by
  SLA state (on-track/due-soon/breached) using the same semantic colors as everywhere else.

**Rules / logic**
- SLA breach fires an internal `ServiceCaseSLABreached` event — WNE Messaging picks it up per
  the tenant's routing rules (e.g. notify supervisor via email + in-app), same decoupled
  pattern as every other module-to-WNE integration.
- Closing a case is final for reporting purposes but reopenable within a configurable grace
  window (default 7 days) — closing by mistake shouldn't require creating a duplicate case.

## 3F. Helpdesk

**Purpose:** general-purpose ticketing — support requests, inquiries, complaints — for any
partner (including a Lead-stage contact, pre-conversion), not necessarily tied to any prior
transaction.

- Fields: requester (Partner, or free-text if pre-CRM/unknown caller), subject, category
  (shared `ticket_categories` lookup with After Sales), priority, status, assigned agent/team,
  channel of origin (email/phone/web-form/in-app), SLA due date.
- Threaded messages (`hd_ticket_messages`) — the actual back-and-forth, distinct from After
  Sales' internal activity log since Helpdesk is conversation-first (closer to email/chat).
- List/detail views mirror After Sales Service (same shared components), but kept as a
  separate engine because: (a) a ticket can exist with no known Partner yet, (b) the
  conversational/threaded nature differs from a case's internal work log, (c) it lets a tenant
  license Helpdesk separately from After Sales Service as a distinct add-on if useful
  commercially.

**Rules / logic**
- If a ticket's requester is later identified as (or converted from) a Lead/Contact, the
  ticket can be re-linked to the resulting Partner without losing the message thread.
- Same SLA-breach → WNE event pattern as After Sales Service.

## 3G. Partner Merge / Deduplication

**Purpose:** keep the registry clean without ever silently losing data.

- **Detection:** background/report view surfaces likely-duplicate partners by
  name/email/phone similarity — a review queue, not automatic merging.
- **Merge action:** admin picks a "surviving" record; the tool re-parents everything that
  referenced the losing record (roles, contacts under a merged Company, leads, tickets, cases,
  activity timeline entries) onto the survivor, and writes a `partner_merge_log` entry
  capturing exactly what was merged and any field-level conflicts (so it's reversible in spirit
  even if not literally one-click undo).
- Vertical modules that FK'd into the now-merged `partner_id` are **not** touched directly by
  CRM (CRM has no access to their schemas) — instead CRM keeps the old partner's row as a
  tombstone (`merged_into_partner_id` set, `is_active = false`) so any existing FK in Sales/
  Legal/Property still resolves, rather than breaking referential integrity across modules.

**Rules / logic**
- Merge is admin-only, tenant-scoped, and always logged — this is a trust-sensitive operation
  for a legal-buyer audience (DESIGN.md: "trust, precision" is the whole brief), so silent or
  irreversible merges are explicitly avoided.

---

# 4. Storage

> Tables and object storage used by this module. Schema: `CRM` (per tenant DB, per §7 of
> CLAUDE.md). Naming: master tables single word; transaction/log tables prefixed by domain
> (`lead_*`, `svc_*`, `hd_*`), matching the convention used in `WNE_SPECS.md`.

**Master / lookup tables**
- `CRM.partners` — unified Company + Contact record. Key fields: `type`
  (individual/organization), `parent_partner_id` (self-referencing, nullable), name fields,
  `is_active`, `merged_into_partner_id` (nullable, tombstone for 3G), `uuid` (external-facing
  reference for future REST clients).
- `CRM.partner_role_types` — tenant-editable lookup (Customer, Vendor, Client, Employee,
  Referral, Other, ...).
- `CRM.addresses` — `partner_id`, type (billing/shipping/office/other), full address fields,
  `is_primary`.
- `CRM.contact_points` — `partner_id`, type (email/phone/mobile/fax), value, `is_primary`,
  `opt_out` (respects "do not contact via X," consumed by WNE routing rules).
- `CRM.industries` — lookup for Companies (optional classification).
- `CRM.lead_sources` — lookup (referral, web, event, cold outreach, ...).
- `CRM.ticket_categories` — shared lookup, used by both After Sales Service and Helpdesk.

**Transaction / log tables**
- `CRM.partner_roles` — `partner_id`, `role_type_id`, `assigned_at`, `assigned_by`,
  `is_active`. (many-to-many, with history)
- `CRM.partner_relationships` — `partner_id`, `related_partner_id`, `relationship_type`
  (works_at / subsidiary_of / referred_by / other) — generalizes affiliations beyond the simple
  `parent_partner_id` column, for cases that aren't a strict hierarchy.
- `CRM.leads` — header: name/company (free text), `source_id`, `stage`, `owner_id`,
  `estimated_value`, `next_action_at`, `converted_partner_id` (nullable, set on conversion),
  `disqualify_reason`.
- `CRM.lead_activities` — `lead_id`, activity type (call/email/meeting/note), body, `logged_by`,
  `logged_at`.
- `CRM.svc_cases` — header: `partner_id`, subject, `category_id`, priority, status,
  `assigned_to`, `sla_due_at`, `subject_type`, `subject_id` (both nullable, informational only).
- `CRM.svc_case_activities` — `case_id`, activity type (note/status_change/attachment), body,
  `logged_by`, `logged_at`.
- `CRM.hd_tickets` — header: `partner_id` (nullable if requester unidentified), subject,
  `category_id`, priority, status, `assigned_to`, `sla_due_at`, channel of origin.
- `CRM.hd_ticket_messages` — `ticket_id`, direction (inbound/outbound/internal-note), body,
  `sender_id` or free-text sender, `sent_at`.
- `CRM.partner_merge_log` — `merged_from_partner_id`, `merged_into_partner_id`, `merged_by`,
  `merged_at`, `field_conflicts` (JSON snapshot of what differed between the two records).

**Object file storage** (per §7B — `tenant_{n}/CRM/` bucket path)
- Ticket/case attachments (Helpdesk messages, After Sales case activity) stored under
  `tenant_{n}/CRM/tickets/{ticket_id}/` and `tenant_{n}/CRM/cases/{case_id}/`, naming
  convention consistent with other modules for restore-ability per tenant.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, same monolithic-modular posture as WNE. Exposes:
- **Internal facade/service** — `PartnerService::findOrCreate(...)`,
  `PartnerService::assignRole(...)`, `LeadService::convert(...)`,
  `ServiceCaseService::open(...)`, `HelpdeskService::open(...)` — the preferred integration
  point for other Core modules (e.g. WNE resolving "who is this notification about").
- **Internal event bus** — publishes `PartnerCreated`, `PartnerRoleAssigned`, `LeadConverted`,
  `ServiceCaseSLABreached`, `TicketCreated`, `TicketStatusChanged`. Vertical modules subscribe
  to these; CRM never subscribes to or calls into vertical modules. This one-way dependency
  (Vertical → Core, never reverse) is the same rule as everywhere else in the codebase.
- **Cross-schema FK, not cross-tenant UUID matching.** Since CRM and every vertical share the
  same tenant database (just different Postgres schemas), a vertical table (e.g.
  `LEGAL.case_hdrs`) can FK directly into `CRM.partners.id` (bigint) — this is safe because
  it's Vertical depending on Core, the allowed direction. The `uuid` column on `partners` exists
  for future external-facing use (REST API for mobile clients per CLAUDE.md §2), not for
  internal joins.

**Vertical linkage without coupling:** `svc_cases` and, optionally, `hd_tickets` carry
`subject_type` / `subject_id` as plain informational columns (e.g.
`subject_type = 'legal.case_hdrs'`), **not** a foreign key. Resolving the actual record (to
show a "view source" link) happens in the frontend/controller layer of whichever module knows
how to look it up — CRM's job stops at storing the pointer. This mirrors the same seam pattern
used for WNE's `subject_type`/`subject_id` on workflow instances.

**Custom fields:** `partners`, `leads`, `svc_cases`, `hd_tickets` are all registered as
extensible entities against the `CUSTOMFIELDS` schema (per CLAUDE.md §7A) — a tenant adding
"Bar Number" to Contacts for the Legal vertical, or "Unit Number" to Companies for Property,
never requires a CRM migration.

**Partner type vs. Role — why they're different concepts:** `type` (individual/organization)
is structural and fixed at creation (a person is not an organization). `role` (Customer/
Vendor/Client/...) is business classification, many-to-many, tenant-configurable, and changes
over time — a Company can gain a "Vendor" role today and a "Client" role next quarter without
becoming a different kind of record. Collapsing these into one field is a common modeling
mistake this design deliberately avoids.

**Why Contacts/Companies are one table but After Sales/Helpdesk are two:** Contacts and
Companies share every field and constantly reference each other (employer/employee) — one
table with a type discriminator is the right seam. After Sales Service and Helpdesk share only
their lookup tables (`ticket_categories`) and SLA mechanics; their lifecycle, threading model,
and even licensing story (a tenant might want Helpdesk without After Sales) genuinely differ —
two tables, sharing components at the UI/service layer rather than the schema layer, is the
right seam there.

**Queues:** SLA-breach checks for `svc_cases`/`hd_tickets` run on a scheduled job (e.g. every
5–15 min) rather than real-time, publishing breach events onto the same `notifications`-adjacent
queue pattern WNE already uses — no need for a new queue, reuse WNE's.

**Extensibility:** New Role types, Lead sources, and Ticket categories are all tenant-editable
lookup tables — no code deploy needed to rename "Client" to "Tenant" for a new vertical, which
is the main lever that makes this Core module reusable across verticals rather than
Legal-specific.

**Suggested build order for Claude Code:** 3B/3C (Contacts/Companies — the Partner registry
everything else FKs into) → 3D (Leads, converts into a Partner) → 3E/3F (After Sales Service +
Helpdesk, share `ticket_categories` lookups and SLA mechanics, need Partner to exist first) →
3G (Partner Merge/Dedup, needs real partner data to operate on) → 3A (Main Dashboard, aggregates
all of the above) — ship at this point.

**Marketability notes**
- The unified Partner + configurable Role model is what lets the *same* CRM module be resold
  under each vertical's vocabulary (Client for Legal, Tenant/Owner for Property) — a genuine
  cost saver for launching future verticals, not just an engineering nicety.
- 360° partner view (all roles, all leads, all tickets, all cases in one record) is a strong
  demo point and a natural "why not just use spreadsheets" pitch for legal buyers who are
  conservative about switching tools.
- Dedup/merge is a data-quality selling point for firms migrating from messy existing systems
  — worth surfacing explicitly in sales demos, not just building quietly.
- Helpdesk and After Sales Service being separate engines (sharing only lookups/components)
  keeps the door open to license them as distinct add-ons later, without a rebuild.

**MVP bias note (Legal is closest to revenue):** for first ship, Leads Kanban, Merge tooling,
and multi-channel Helpdesk (phone/web-form intake beyond email) can be trimmed — the minimum
sellable version is: Partners (Contacts+Companies) with Roles, a simple Lead list-to-convert
flow (skip the Kanban board), and Helpdesk/After Sales as flat ticket lists without SLA
automation. All of that fits the schema above without changes — it's a UI/feature-flag
reduction, not a re-architecture, so building the fuller version now doesn't cost extra
migration pain later.
