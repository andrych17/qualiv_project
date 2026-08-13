# CENTRAL Module
## Platform Tenant Registry, Subscription Billing & Access Governance — Foundational Platform-Level Module (lives in the Central DB, outside every tenant boundary)

# 1. Backgrounds

> Pain point and business value.

`ARCHITECTURE.md` and `CLAUDE.md` §4 already establish that a Central DB (`nusaevo`) exists,
holding `tenants`, `tenant_user_lookups`, and `tenants.plan` — but as three
referenced-but-unspecced tables. That's the same "infrastructure everyone already assumes
exists" gap `SYSCONFIG_SPECS.md` closed for menus/consts/serials and `CUSTOMFIELDS_SPECS.md`
closed for EAV — except one level further down the stack. `SYSCONFIG` formalized "what every
tenant DB assumes exists inside itself"; **`CENTRAL` formalizes what has to exist *before* a
tenant DB exists at all.** `CLAUDE.md` §11 already flags this gap explicitly: *"Tenant SaaS
subscription billing (how the platform itself charges each tenant for their plan) —
`tenants.plan` string exists in the central DB; no payment provider integration yet."*

Left unformalized, this repeats the exact anti-pattern every other `*_SPECS.md` in this
platform exists to prevent — except here the cost of getting it wrong is existential rather
than architectural, since it's the mechanism that turns the platform from software into a
paid business:

- **No formal tenant record.** `tenants` is referenced everywhere (session-bound tenant
  resolution per `CLAUDE.md` §4, `TenantFeatureService`, `stancl/tenancy`) but its actual
  shape — provisioning state, DB reference, billing status — has never been specced.
- **No data-driven module entitlement.** `config/tenant_modules.php` is a static PHP config
  file per plan tier (`CLAUDE.md` §4). That's fine for "what does the Starter plan include,"
  but there's no record of a *specific* tenant's actual subscribed modules, add-ons bought à
  la carte (several modules in this platform — DMS, Schedule, Inventory, Accounting,
  Performance, Purchase — are explicitly speced as standalone-sellable line items, per each
  spec's own Marketability notes), or plan changes over time.
- **No SaaS billing exists at all.** Simon (the platform owner) has no way to invoice a
  tenant for their subscription, track what's owed, or reconcile a payment — the single
  biggest hole in "this is a rentable, multi-tenant SaaS product" (`CLAUDE.md` §1).
- **Manual bank transfer is the dominant SMB payment method in this market.** Few early
  Indonesian SMB tenants will have a corporate card or an integrated payment gateway on day
  one — the realistic MVP flow is "tenant transfers to Simon's bank account, uploads a proof
  of payment, Simon confirms it," not a live payment gateway (the same "manual-first, gateway
  later" bias already applied to `SALES_SPECS.md`'s own Payment Gateway Future Version item
  and `PAYROLL_SPECS.md`'s bank-file-export MVP).
- **No consequence for non-payment.** A tenant can run indefinitely unpaid today, with no
  reminder, no soft cutoff, and no clear operational signal to Simon about who's overdue —
  a real revenue-leak risk for a solo-dev-run business with no billing team to chase this
  manually.
- **This module must never depend on anything inside a tenant DB.** WNE, DMS, CRM, SYSCONFIG,
  and every module specced so far all assume a tenant DB already exists and lives *inside*
  it. `CENTRAL` is the layer that creates and governs those DBs — it is the one module in
  this platform that must be entirely self-contained, with **zero dependency on any
  tenant-scoped module**, the same "zero dependency" posture `SYSCONFIG_SPECS.md` §5 claims
  for itself, taken one level further: `SYSCONFIG` has zero dependency on other *tenant*
  modules, but it still lives *inside* a tenant DB and needs one to exist first. `CENTRAL`
  doesn't even have that.

**Client requirements:**
- A central registry of every tenant: company/contact info, provisioning status, tenant DB
  reference, current plan.
- Per-tenant, data-driven module/plan configuration — not just the static plan-tier config
  file — so an individual tenant's actual subscribed modules (base plan + à la carte add-ons)
  are real, queryable data, not inferred from a plan string alone.
- Recurring subscription billing: an invoice per tenant per billing cycle, itemized by plan
  fee + any add-on module fees.
- A manual payment-confirmation workflow: the tenant (or Simon, on their behalf) uploads a
  payment receipt; Simon reviews and confirms or rejects it.
- Automated reminders before/on/after the due date, and a **configurable-day soft cutoff**
  into read-only mode if a tenant goes unpaid past that window — protective of the business
  without being punitive: tenant data is never touched, access simply degrades to read-only
  until payment resumes, then reverts automatically.
- Must **not** become a second Accounting system — no double-entry GL, no PPN/PPh engine, no
  Faktur Pajak — just enough invoice/payment-tracking discipline for the platform's *own*
  operations. See §5 for the explicit disambiguation from the tenant-facing Accounting module.

# 2. Goals

> Designated features. MVP-first — this is prerequisite infrastructure for the business to
> function as a paid product at all, so a correct, minimal core matters more than a rich
> revenue-ops layer on day one.

**MVP (ship first — this is what makes the platform a business, not just software)**
- **Tenant Registry & Provisioning** (§3B) — the canonical `tenants` record, provisioning
  status, and the trigger point into `stancl/tenancy`'s actual DB creation.
- **Plan & Module Entitlement Configuration** (§3C/§3D) — a plan catalog plus per-tenant
  add-on modules, resolved into the entitlement `SYSCONFIG.tenant_modules` (per-tenant
  visibility toggle, `SYSCONFIG_SPECS.md` §3A) can only ever *narrow*, never widen.
- **Billing / Invoice Engine** (§3E) — generates a `central_invoices` row per tenant per
  billing cycle, itemized from the plan + add-ons currently in force.
- **Payment Capture & Confirmation, incl. receipt upload** (§3F) — a lightweight, manual
  proof-of-payment flow: tenant uploads a receipt, Simon confirms or rejects.
- **Dunning Engine — Reminders & Soft Cutoff** (§3G) — a scheduled job that fires reminder
  notices on a configurable day schedule relative to the due date, and flips a tenant to
  `read_only` access after a configurable number of days past due if still unpaid; reverts to
  `active` automatically the moment payment is confirmed.
- **Tenant-Facing Billing Screen** (§3H) — the one small, deliberately-scoped surface a
  logged-in tenant admin sees *inside their own tenant app* to view invoices and submit a
  payment receipt, even while `read_only`.
- **Central Admin Dashboard** (§3A) — Simon's own operational snapshot: tenants by status,
  revenue this period, overdue tenants, upcoming renewals, pending payment reviews.
- **Central Audit Log** (§3I) — append-only record of every registration, entitlement,
  invoice, payment-review, and access-status change, same immutable-audit posture every other
  module in this platform already applies to its own sensitive data.

**Future Version (explicitly deferred — do not build now)**
- **Payment gateway integration** (Xendit/Stripe/local rails) for tenant self-serve online
  payment — MVP is manual bank transfer + receipt upload only, mirroring `SALES_SPECS.md`'s
  and `PAYROLL_SPECS.md`'s own deferred-gateway posture.
- **Self-service signup/upgrade/downgrade** — MVP is admin-driven (Simon creates and adjusts
  tenants); a self-serve flow is a natural fast-follow once there's real inbound demand.
- **Usage-based/metered billing** (per-seat, per-transaction-volume) — MVP is flat plan-tier
  pricing only.
- **`central_tenant_module_grants`** — a per-tenant entitlement override layered on top of
  plan + add-ons (e.g. comping a beta feature, time-boxing an AIInsight trial for one tenant
  without a code deploy). This is the same table `SYSCONFIG_SPECS.md`'s own Future Version
  section already forward-references (`central.tenant_module_grants`) — it stays deferred
  here too, for the same reason: build only once a real commercial need for per-tenant
  overrides beyond plan+add-ons actually shows up. The schema in §3C is shaped so this is a
  purely additive table later, not a redesign.
- **Automated end-to-end provisioning** — MVP: a confirmed payment (or an admin override for
  a comped/trial tenant) triggers a semi-automated provisioning step Simon reviews once per
  new tenant, since volume is low enough that this isn't a bottleneck yet. The provisioning
  *hook* (§3B) is designed so this becomes fully automatic later without a schema change.
- **Free trial periods** — MVP has no trial concept; a tenant is either provisioned against a
  paid/comped plan or not provisioned. A trial is additive (a `trial_ends_at` column + a
  dunning-policy variant) once there's a concrete go-to-market reason to offer one.
- **Hard cutoff / suspension / data export & deletion** after prolonged non-payment — MVP
  only ever reaches `read_only`. Deciding what happens after weeks or months of continued
  non-payment (data retention obligations, export-before-delete, legal notice requirements)
  is a distinct, higher-stakes policy decision deliberately not bundled into this MVP.
- **Central-level revenue analytics** (MRR/ARR, churn, cohort retention) — worth building
  once there are enough tenants for the analysis to be meaningful; a natural candidate to
  reuse the same "ask your data" pattern **AIInsight Core** already establishes, applied to
  the Central DB instead of a tenant DB, once that's a real need.
- **Multi-admin roles/approval on payment review** — MVP has exactly one platform admin
  (Simon); a second approver step is only worth building once there's an actual second
  admin, at which point it would reuse a workflow-style gate conceptually, not a bespoke
  engine.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules,
> database design.

## 3A. Central Admin Dashboard

**Function / features**
- Platform-health snapshot: total tenants by `access_status` (active / past_due / read_only),
  MRR-equivalent (sum of active plan + add-on fees), invoices issued this period, payments
  pending review, tenants entering their cutoff window in the next N days.
- "Needs attention" queue: unreviewed payment receipts, invoices past due, tenants approaching
  soft cutoff, provisioning requests awaiting Simon's manual step.
- Quick actions: register a new tenant, review a pending payment, adjust a tenant's plan/
  add-ons, manually reactivate a tenant (with a logged reason).

**Layout**
- Top: summary cards — Active Tenants, Past Due, Read-Only, Payments Pending Review.
- Main: tabbed table — "Pending Payment Review" | "Overdue Invoices" | "Approaching Cutoff" |
  "Recent Tenants" — reusing the same Data Table + **Status Rail** component convention
  (`DESIGN.md`) as every tenant-facing module, so the admin surface still feels like part of
  one platform, not a separate ops tool bolted on the side.
- Row click opens a drawer: tenant detail, invoice/payment history, entitlement history,
  audit trail (§3I).

**Rules / logic**
- This dashboard is **platform-admin-only** (`central_admin_users`, §4) — it is not reachable
  from inside any tenant's own session, the mirror image of the tenant-facing screen in §3H.
- Overdue and pending-review items surface first regardless of sort, same "breach surfaces
  first" convention every other module's dashboard already follows (WNE §3A, CRM §3A,
  Accounting §3A, Purchase §3A, Legal §3A).

## 3B. Tenant Registry & Provisioning

**Purpose:** the single canonical record of "who is a customer of this platform," and the
trigger point into actually standing up their tenant DB.

- Fields: company/legal name, primary contact (name, email, phone), billing address,
  `plan_code` (FK `central_plans`, §3D), `provisioning_status`
  (`pending` → `provisioning` → `provisioned` → `active`) and, separately, `access_status`
  (`active` / `read_only` — the billing-driven access gate, §3G), `tenant_db_name` (the actual
  Postgres database `stancl/tenancy` creates), `provisioned_at`, `notes`.
- **Registration action:** Simon (or, in Future Version, a self-service signup) creates a
  `tenants` row in `pending` status. Nothing about tenant infrastructure exists yet at this
  point — this is deliberately just the *intent* to onboard a customer.
- **Provisioning action:** once Simon confirms the tenant is ready to go live (first invoice
  issued and, in MVP, paid — or explicitly comped), a "Provision" action calls into
  `stancl/tenancy`'s tenant-creation flow (creates the Postgres database, runs tenant
  migrations, seeds `SYSCONFIG` defaults per `ARCHITECTURE.md` §1.4's `TenantFlavorSeeder`
  pattern, creates the first tenant admin user), then flips `provisioning_status` to
  `provisioned` and `access_status` to `active`.
- Detail view: tenant header + tabs — Plan & Entitlement (§3C), Invoices (§3E), Payments
  (§3F), Dunning History (§3G), Audit Log (§3I).

**Rules / logic**
- A tenant's `tenant_db_name` is immutable once provisioned — renaming/migrating a tenant's
  underlying database is an infrastructure operation, not a Central-module data edit.
- `tenants` is the **one** table in this entire platform that legitimately needs a per-row
  identity spanning all tenants — the opposite end of the spectrum from every other module's
  "no `tenant_id` column, DB-per-tenant is the isolation boundary" rule (`CLAUDE.md` §4/§7).
  That rule describes *tenant-DB* tables; `CENTRAL` is the one place a table listing every
  tenant is exactly correct, since enumerating tenants is this module's entire job.

## 3C. Plan & Module Entitlement Configuration

**Purpose:** turn "which modules can this specific tenant see" into real, queryable data
instead of a plan string alone — the concrete mechanism `SYSCONFIG_SPECS.md` §3A already
assumes exists when it describes "the existing central-DB entitlement mechanism
(`tenants.plan` + `config/tenant_modules.php` + `TenantFeatureService`)."

- A tenant's **effective entitlement** for a given `module_code` = the union of:
  1. Every module included by their current `plan_code` in `central_plan_modules` (§3D).
  2. Every module explicitly added via `central_tenant_addons` (à la carte purchases on top
     of the base plan — e.g. a tenant on a Legal-vertical plan who separately buys standalone
     Performance, matching the "standalone-sellable" positioning already established for
     DMS/Schedule/Inventory/Accounting/Purchase/Performance in their own specs' Marketability
     notes).
- `central_tenant_addons`: `tenant_id`, `module_code`, `added_at`, `price_override` (nullable
  — for a negotiated rate), `status` (`active`/`removed`). Removing an add-on is a status
  flip, not a delete — same non-destructive posture as everywhere else in this platform.
- `TenantFeatureService::isEntitled(tenantId, moduleCode)` reads this resolved union — the
  **one** function every tenant-DB middleware (`module:CODE`, `CLAUDE.md` §4) and every
  tenant's own `SYSCONFIG.tenant_modules` visibility check (`SYSCONFIG_SPECS.md` §3A) ultimately
  calls back into.

**Rules / logic**
- **Entitlement here is the hard ceiling; `SYSCONFIG.tenant_modules` can only narrow it,
  never widen it** — restating `SYSCONFIG_SPECS.md` §3A's own rule from the other side:
  *"Effective visibility = entitled (central) AND `is_active` (SYSCONFIG)."* `CENTRAL` is the
  `entitled` half of that equation; `SYSCONFIG` is the `is_active` half. Neither module can do
  the other's job.
- Changing a tenant's plan or add-ons is logged to `central_audit_logs` (§3I) — an entitlement
  change is a billing-relevant event, not a quiet toggle.
- This mechanism is deliberately data-driven per the same "prefer data over a code deploy"
  bias used throughout `ARCHITECTURE.md`'s customization ladder — except here it's one rung
  *below* the ladder described there, since the ladder assumes a tenant DB already exists.

## 3D. Subscription Plan Catalog (Master)

**Purpose:** the pricing/packaging configuration Simon edits when plans change — never
hardcoded in application code.

- `central_plans`: `code` (e.g. `LEGAL_STARTER`, `LEGAL_PRO`), name, description,
  `price_monthly`, `price_annual`, currency (IDR default), `is_active`.
- `central_plan_modules`: pivot, `plan_code` × `module_code` (same code space as
  `SYSCONFIG.tenant_modules.module_code`, §3A of `SYSCONFIG_SPECS.md`) — the default module
  set a tenant on that plan is entitled to before any à la carte add-ons (§3C).
- Simple list/detail admin screens, same shared Data Table/Form component conventions
  (`DESIGN.md`) as every other master-data screen across the platform.

**Rules / logic**
- Deactivating a plan (`is_active = false`) blocks new tenants from being assigned to it but
  never affects tenants already on it — same non-destructive deactivation pattern used for
  every other lookup table in this platform (CRM's role types, Legal's deed types, HCM's leave
  types, ...).
- Plan pricing changes apply to invoices generated *after* the change; an already-issued
  invoice is never retroactively recalculated — matches the "never mutate a posted financial
  record" discipline `ACCOUNTING_SPECS.md` §3O and `PAYROLL_SPECS.md` §3-Admin both apply to
  their own transaction records.

## 3E. Billing / Invoice Engine

**Purpose:** generate what a tenant owes, on schedule, itemized and auditable — deliberately
lightweight, not a second Accounting module (see §5's explicit disambiguation).

- `central_invoices`: `tenant_id`, `billing_period_start`/`billing_period_end`, `plan_code`
  (snapshotted at issue time — a later plan-price change never rewrites a past invoice, same
  reasoning as §3D), status (`draft` → `issued` → `payment_submitted` → `paid` / `overdue` /
  `void`), `amount_total`, currency, `due_date`, `issued_at`.
- `central_invoice_lines`: `invoice_id`, description, amount — one line for the base plan fee,
  one additional line per active add-on module (§3C) at the time of generation.
- **Generation**: a scheduled job runs per tenant's billing interval (monthly/annual, from
  `central_plans`), snapshots current plan + active add-ons into a new `draft` invoice, then
  flips it to `issued` — the point a tenant can see and act on it (§3H).
- **Recurring, not one-off**: mirrors the same "generate a draft on schedule, never
  auto-post/auto-charge silently" caution `ACCOUNTING_SPECS.md` §3P applies to its own
  Recurring Transactions engine — except here `issued` is the equivalent of "posted," since
  there's no further approval step needed for a subscription invoice at this scale.

**Rules / logic**
- An invoice is **voided, never deleted**, if it needs to be cancelled (e.g. a tenant is
  comped mid-cycle) — same non-destructive financial-record discipline used throughout
  `ACCOUNTING_SPECS.md` and `SALES_SPECS.md`.
- `status = overdue` is a **derived** state (issued + past `due_date` + not yet `paid`),
  recomputed by the dunning job (§3G), not a value anything sets directly.

## 3F. Payment Capture & Confirmation (incl. Receipt)

**Purpose:** the manual, bank-transfer-first payment flow this market actually needs at MVP —
tenant submits proof, Simon confirms it.

- `central_payments`: `invoice_id`, `tenant_id`, amount, method (`bank_transfer` in MVP;
  reserved values for a Future Version gateway), `receipt_object_key` (R2 object reference,
  §4), status (`pending_review` → `confirmed` / `rejected`), `submitted_at`, `reviewed_by`
  (`central_admin_users`), `reviewed_at`, `rejection_reason` (nullable, required if rejected).
- **Submission** (from §3H, the tenant-facing screen): tenant uploads a receipt image/PDF and
  states the amount/date transferred → creates a `central_payments` row in `pending_review`,
  invoice moves to `payment_submitted`.
- **Review** (from §3A, the admin dashboard): Simon inspects the receipt, confirms
  (`invoice.status → paid`, `tenant.access_status` reverts to `active` immediately if it had
  been `read_only`, §3G) or rejects (with a required reason, invoice reverts to `issued`/
  `overdue`, tenant is notified per §3G's channel and can resubmit).

**Rules / logic**
- Confirming a payment is the **only** action that flips an invoice to `paid` — no automatic
  "assume paid" path exists in MVP, since there's no live gateway to verify against; this is
  the deliberate manual-review tradeoff of a bank-transfer-first flow, matching how
  `PAYROLL_SPECS.md`'s own MVP Payment Reconciliation (§3-Payment) is manual/file-based rather
  than a live bank API for the same reason.
- A rejected payment's receipt file is retained (never deleted) for Simon's own record-keeping
  — same "never destroy evidence" posture as DMS's retention/legal-hold discipline, applied
  here to financial evidence instead of legal documents.
- Receipt upload (and viewing invoice/payment history) must remain reachable **even while a
  tenant is `read_only`** — this is the one deliberate, explicit exception to the soft-cutoff
  enforcement in §3G/§5, since otherwise a cut-off tenant would have no way to pay their way
  back to `active`.

## 3G. Dunning Engine — Reminders & Soft Cutoff

**Purpose:** the automated, configurable mechanism that protects the business without being
punitive — reminders first, then a graceful read-only degrade, never data loss.

- `central_dunning_policies`: `scope_type` (`platform_default` / `plan` / `tenant`, resolved
  most-specific-wins — the same override-ladder *idea* `SYSCONFIG.config_consts`'s two-tier
  precedence engine already applies inside a tenant DB (`SYSCONFIG_SPECS.md` §3E), mirrored
  here one layer up even though it can't literally be the same engine, since this is a
  different database entirely), `scope_id` (nullable — a `plan_code` or `tenant_id`,
  depending on `scope_type`), `reminder_offsets_days` (JSON array of day offsets relative to
  `due_date`, e.g. `[-7, -3, -1, 3, 7]` — negative = before due, positive = past due),
  `cutoff_days_after_due` (e.g. `14`), `cutoff_action` (VARCHAR + CHECK — `read_only` is the
  only value in MVP, matching the platform's own established "status/type fields as VARCHAR +
  CHECK, not a native enum" convention).
- **Reminder job** (daily scheduled): for every `issued`/`overdue` invoice, compares today
  against `due_date + each configured offset`; on a match, sends one reminder (email, MVP's
  only channel — see §5 for why this doesn't reuse WNE) and logs it to `central_dunning_log`
  (`tenant_id`, `invoice_id`, offset fired, channel, `sent_at`) — checked first so the same
  offset is never sent twice for the same invoice.
- **Cutoff job** (same daily run): for every `overdue` invoice past `due_date +
  cutoff_days_after_due` with no `paid` status, flips `tenants.access_status → read_only` and
  logs the transition to `central_audit_logs` (§3I).
- **Reactivation**: the moment a payment is confirmed (§3F) for the invoice that caused the
  cutoff, `access_status` reverts to `active` **automatically** — no separate manual
  "reactivate" step required, though one exists on the admin dashboard (§3A) for exceptional
  cases (e.g. Simon comping an overdue tenant).

**Rules / logic**
- `reminder_offsets_days` and `cutoff_days_after_due` are **tenant-editable-by-Simon
  configuration**, per the requirement — never hardcoded, resolved per the scope precedence
  above (a specific tenant's negotiated terms can override the platform default without a
  code change).
- Soft cutoff (`read_only`) **never touches tenant data** — it is purely an access-control
  state, enforced by the middleware described in §5, not a data-layer restriction. This
  mirrors the same non-destructive philosophy every module in this platform already applies to
  its own domain (DMS never deletes on retention expiry without explicit action, Payroll Lock
  blocks edits but never deletes a run, CRM merge never destroys the losing record) — applied
  here to the tenant relationship itself, not just a record inside it.
- Hard suspension/deletion is explicitly out of scope for MVP (§2 Future Version) — this
  engine's only enforcement lever today is `read_only`.

## 3H. Tenant-Facing Billing Screen (cross-boundary)

**Purpose:** the one small, deliberately narrow surface a tenant's own admin user sees
*inside their own tenant app* — even though the data it reads/writes lives in a completely
different database (Central, not their tenant DB).

- Screen: "Billing & Subscription" (reachable from the tenant's own account/settings area,
  not gated by `SYSCONFIG` menu rights since it isn't tenant-DB data at all) — shows current
  plan + add-ons, invoice history with status, and a "Submit Payment" action (amount, date,
  receipt upload) for any `issued`/`overdue`/`payment_submitted` invoice.
- **Technical approach**: this is *not* a REST/API call to a separate service — per
  `CLAUDE.md` §2's Web boundary policy ("do not build REST/GraphQL endpoints for web pages...
  ship REST only when a non-Inertia client is real"), this stays inside the same Laravel
  monolith. Laravel supports multiple database connections from one application; a thin
  `Controllers/BillingController` inside the tenant-facing app queries the `central`
  connection directly (Eloquent models bound to that connection) and still returns an
  ordinary `Inertia::render(...)` response. This is a **connection boundary**, not a service
  boundary — no new deployable, no new API surface, fully consistent with "modular monolith
  first" (`CLAUDE.md` §2).

**Rules / logic**
- This screen must remain reachable regardless of `access_status` — it is the explicit
  exception to read-only enforcement noted in §3F/§3G/§5, since it's the only path back to
  `active`.
- Only a tenant's designated admin user(s) can see this screen (a flag on the tenant's own
  user record, resolved the same way any other "admin-only" screen is gated elsewhere in this
  platform) — a general tenant user has no reason to see the firm's own subscription billing.

## 3I. Central Audit Log

- `central_audit_logs`: append-only, one row per action (`tenant_registered`,
  `tenant_provisioned`, `plan_changed`, `addon_added`, `addon_removed`, `invoice_issued`,
  `invoice_voided`, `payment_submitted`, `payment_confirmed`, `payment_rejected`,
  `access_status_changed`, `dunning_policy_changed`), actor (`central_admin_users` row, or
  `system` for scheduled-job actions), timestamp, entity reference, before/after snapshot —
  same immutable-audit posture as `dms.access_logs`, `wne.wrkflow_audit_logs`,
  `acct.audit_logs`, `sysconfig.config_audit_logs`, and `field_def_audit_logs`.
- No update/delete permitted on this table at the app layer, same rule as every other audit
  log across the platform.
- A single unified log (rather than one table per concern) is a deliberate MVP-scale choice —
  Central's write volume is orders of magnitude lower than a tenant's own transactional
  modules, so one `entity_type`-discriminated append-only table is enough; `central_dunning_log`
  (§3G) stays separate because it does double duty as *functional* state (preventing a
  duplicate reminder send), not just historical record.

# 4. Storage

**Database: Central DB (`nusaevo`)** — no per-module schema separation the way tenant DBs use
`SYSCONFIG.`/`WNE.`/`CRM.` etc. (`CLAUDE.md` §7A); this database has exactly one job, so a flat
table namespace is sufficient. `tenants` and `tenant_user_lookups` are the two tables
`ARCHITECTURE.md` already references and are extended here rather than redefined; every other
table below is new, prefixed `central_` for clarity against the two pre-existing ones.

**Registry / master tables**
- `tenants` *(existing, extended)* — company/contact info, `plan_code` (FK `central_plans`),
  `provisioning_status`, `access_status`, `tenant_db_name`, `provisioned_at`.
- `tenant_user_lookups` *(existing, unchanged)* — email → tenant_id lookup for login-bound
  tenant resolution, per `CLAUDE.md` §4.
- `central_plans` — plan catalog (§3D).
- `central_plan_modules` — plan × module_code pivot (§3D).
- `central_admin_users` — platform admin accounts, separate from any tenant's own users.

**Entitlement / billing transaction tables**
- `central_tenant_addons` — à la carte modules on top of a tenant's base plan (§3C).
- `central_tenant_module_grants` — **Future Version**, per §2 and per
  `SYSCONFIG_SPECS.md`'s own forward reference; not built in MVP.
- `central_invoices`, `central_invoice_lines` — subscription billing (§3E).
- `central_payments` — payment submissions + review outcome, including `receipt_object_key`
  (§3F).
- `central_dunning_policies` — configurable reminder/cutoff schedule, scope-resolved (§3G).

**Log tables**
- `central_dunning_log` — append-only, functional (prevents duplicate sends) + historical
  (§3G).
- `central_audit_logs` — append-only, general platform audit trail (§3I).

**Object file storage** — a dedicated top-level prefix in the same shared Cloudflare R2
bucket, distinct from the per-tenant `tenant_{id}/` convention (`CLAUDE.md` §7B), since this
data belongs to the platform's own billing relationship with a tenant, not to anything inside
that tenant's own DB:
```text
central/
├── tenants/{tenant_id}/receipts/{payment_id}/{filename}   # uploaded proof of payment
└── tenants/{tenant_id}/invoices/{invoice_id}/invoice.pdf  # optional generated invoice PDF
```
- Central implements its own minimal, flat, non-versioned upload-and-store for receipts — no
  version history, no retention/legal-hold engine, no OCR hooks. It deliberately does **not**
  route through **DMS** (see §5) — a receipt is simply kept indefinitely as financial
  evidence, the simplest possible policy for the lowest-volume, least-frequently-touched
  document type in the platform.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern — a new category, not Core or Vertical.** `CLAUDE.md` §2 now names
**Platform-level** as a fourth category alongside Core/Vertical/Microservice: the one kind of
module that exists entirely outside any tenant DB, and that every tenant DB's own existence
depends on, rather than the reverse. `CENTRAL` is the first (and so far only) module in this
category.

**Build order:** `CLAUDE.md` §5 now lists `CENTRAL` as an explicit **step 0**, before
`SYSCONFIG`: build enough of the Tenant Registry (§3B) and a minimal manual-provisioning path
to stand up the *first* tenant DB Claude Code will then build `SYSCONFIG` inside —
Billing/Dunning (§3E–§3G) can follow once there's a real second or third paying tenant to bill,
but the registry/provisioning piece has to exist first, structurally.

**Module boundary (same "Layer | Owns | Must not" convention `ARCHITECTURE.md` §2.2 already
uses):**

| Layer | Owns | Must not |
|-------|------|----------|
| `CENTRAL` | Tenant registry, plans, entitlement, invoices, payments, dunning | Import any tenant-scoped module's models (WNE, DMS, SYSCONFIG, ...) — none of them exist until Central provisions the DB they live in |
| `SYSCONFIG` (per tenant) | `tenant_modules` visibility toggle, narrows Central's entitlement | Grant a module Central hasn't entitled, or assume it can widen what Central grants |
| Tenant's own `ACCOUNTING` (per tenant) | That tenant's own customers' AR/AP, PPN/PPh compliance | Record Simon's own SaaS subscription revenue — a different company's books entirely |

**Why this is not a second Accounting module — explicit disambiguation (same pattern already
used for `LEGAL.deed_taxes` vs. `ACCOUNTING`'s tax engine, `LEGAL_SPECS.md` §3K):**
`central_invoices`/`central_payments` track what a **tenant owes Simon's company** for
platform access — a lightweight receivable tracker, not a statutory-compliant bookkeeping
system. It has no double-entry GL, no PSAK presentation, no PPN/Faktur Pajak handling. If
Simon eventually wants formal, tax-correct books for his own company's SaaS revenue, that's a
genuinely separate concern (e.g. his own instance of the `ACCOUNTING` module as a tenant of his
own platform, or external bookkeeping) — explicitly out of scope for `CENTRAL`, which only
needs to answer "has this tenant paid, and are they current" correctly enough to drive access
control and reminders.

**Why this doesn't reuse WNE.** Every other module in this platform is told to reuse WNE for
approvals/notifications rather than build a parallel path — the opposite advice applies here.
WNE is a **tenant-scoped** module; it doesn't exist until a tenant DB is provisioned, and
`CENTRAL` operates both before that point (registration/provisioning) and independently of any
single tenant's WNE instance (billing spans all tenants). This is the one legitimate place in
the platform where "don't build a parallel notification path" doesn't apply, because there is
no shared engine at this layer to reuse. Dunning (§3G) therefore implements its own minimal,
single-channel (email) send — no multi-channel driver interface, no user preference center, no
retry/DLQ machinery — deliberately far lighter than WNE's own Notification Module, since the
volume and stakes at this layer don't justify that machinery yet.

**Why this doesn't reuse DMS.** Same reasoning — DMS is tenant-scoped and, in the exact
scenario this module cares about most (an overdue tenant trying to submit a receipt to get
reactivated), routing through a tenant's own DMS instance would be circular: DMS's own
retention/versioning engine has no reason to exist for a document that isn't really "this
tenant's data" at all, it's evidence in Simon's relationship *with* the tenant. `CENTRAL`
implements its own trivial flat storage (§4) instead.

**Enforcement mechanism for `read_only` access.** A global Laravel middleware
(`EnsureTenantStanding`, applied ahead of every state-changing route — POST/PUT/PATCH/DELETE
— across every tenant module) checks the current tenant's `access_status` from `CENTRAL`
before allowing the request through. Because this check would otherwise run on every
state-changing request across the whole platform, the value is cached in Redis
(`central:tenant:{id}:access_status`), invalidated the moment §3F/§3G changes it — the same
"cache the resolved value, invalidate on write" pattern already used for
`SYSCONFIG.config_consts` (`SYSCONFIG_SPECS.md` §3E) and `CUSTOMFIELDS.field_defs`
(`CUSTOMFIELDS_SPECS.md` §5). A blocked request returns a clear, calm message per `DESIGN.md`
§5's voice guidance — *"Your subscription is past due. You can still view your data, but
changes are paused until payment is confirmed."* — with a direct link to §3H's Billing screen,
which is explicitly allowlisted through this same middleware alongside read-only (GET) routes
everywhere else.

**Idempotency/concurrency notes:**
- Invoice generation (§3E) is a scheduled job keyed by `(tenant_id, billing_period)` with a
  uniqueness constraint — re-running the job never creates a duplicate invoice for a period
  already billed.
- `central_dunning_log` existing for a given `(tenant_id, invoice_id, offset)` combination is
  what prevents the reminder job from double-sending the same notice if it's re-run or
  overlaps a slow prior run.
- Payment confirmation (§3F) and the cutoff job (§3G) both write to `tenants.access_status`;
  confirming a payment always wins over an in-flight cutoff check for the same tenant (a
  service-layer transaction, not a DB trigger, keeping this explicit and easy to reason about
  for a solo dev re-reading it later — same "explicit, boring code" bias `CLAUDE.md` §6
  states as a general coding convention).

**Suggested build order for Claude Code:** §3B minimal Tenant Registry + manual provisioning
trigger (the literal step 0 — this has to exist before `SYSCONFIG` or any Core module can be
built against a real tenant DB) → §3D Plan Catalog → §3C Entitlement (plan + add-ons,
resolved via `TenantFeatureService`) → §3E Invoice generation → §3F Payment capture +
confirmation (manual review first, no gateway) → §3H Tenant-facing Billing screen (the
cross-boundary Eloquent-connection pattern) → §3G Dunning (reminders, then cutoff) → §3A
Central Admin Dashboard (ties the above together for Simon's own daily use) → §3I Audit Log
(cheap to retrofit once the write paths above are stable, but log from day one on each, not
bolted on at the end) — **ship here**, since this is what makes taking on a second or third
paying tenant operationally sane, not just possible.

**Marketability notes**
- This module is invisible to every tenant except through the one narrow Billing screen
  (§3H) — but it's what makes every other module's "standalone-sellable" story (DMS,
  Schedule, Inventory, Accounting, Purchase, Performance) actually monetizable as à la carte
  add-ons rather than just an architectural nicety, since §3C's plan+add-on model is the
  literal billing mechanism behind that packaging strategy.
- A clean, calm read-only degrade (never data loss, never a punitive lockout) is itself a
  trust signal worth stating plainly to prospective tenants — a conservative legal-buyer
  audience (`DESIGN.md`'s stated brief) will ask "what happens if we're late on an invoice,"
  and "you can still see everything, you just can't change it until we're square" is a much
  better answer than either silence or an abrupt suspension.
- Configurable dunning windows per tenant (§3G) let Simon offer better terms to a strategic
  early client without any code change — the same "data, not a deploy" story every other
  module in this platform already tells, applied to the platform's own commercial terms.
