# SYSCONFIG Module
## System Configuration, Access Control & Runtime Settings — Foundational Core Module (no standalone story; every other module depends on it)

# 1. Backgrounds

> Pain point and business value.

Every other module in this platform already leans on `SYSCONFIG` conceptually — it's the
first schema listed in `CLAUDE.md` §4/§7's structure, it's rungs 1–2 of `ARCHITECTURE.md`'s
customization ladder (consts, serials), and `ARCHITECTURE.md` §3.2.A/§3.3 already shows a
working example (`LEGAL.CASE_PREFIX`, `LEGAL.URGENT_SETS_PENDING`, `ConfigSnumService::next()`)
that assumes `config_consts`/`config_snums` exist and behave a specific way. But unlike WNE,
DMS, CRM, and every module built after them, `SYSCONFIG` never received its own `*_SPECS.md`.
Left informal, this creates the exact risk every other spec in this platform exists to avoid:

- Three settings concerns have no formal home: **which modules a tenant currently has
  switched on**, **general tenant-wide settings**, and **module-level settings/switches** —
  each currently would-be solved ad hoc, per module, if not addressed here.
- Small, rarely-changing lookup data (a handful of fixed classification codes) forces a choice
  between a hardcoded PHP `enum`/`match` (a code deploy to add one value) or a proliferation of
  near-empty single-purpose tables — real schema clutter for a solo dev to maintain, for data
  that's genuinely just a handful of rows that almost never change.
- There's no formal override hierarchy for a setting — today a const is effectively
  tenant-wide-only. There's no consistent way to say "this behaves differently inside the
  Legal module" or "for this specific user" without ad hoc special-casing in application code,
  which is exactly what the customization ladder in `ARCHITECTURE.md` exists to prevent.
- Menus, groups, and access rights are referenced as existing, working infrastructure
  (`CLAUDE.md` §4: middleware `menu.perm:MENU_CODE`) but have no formal schema anywhere —
  Claude Code has nothing concrete to build or extend against.
- Nearly every other module's spec promises "this is tenant-editable data, never a hardcoded
  constant" (Legal's tax rates, HCM's statutory tables, WNE's SLA hours, Accounting's control
  thresholds) — `SYSCONFIG` is the module that promise structurally depends on. Without a
  formal spec, that promise has no enforced shape platform-wide.

**Client requirements:**
- Three settings arrangements, formalized: **module activation** (which modules a tenant has
  turned on), **general settings** (tenant-wide), and **module-level settings** (scoped to one
  module) — all through a consistent, minimal set of mechanisms, not one bespoke table per
  module.
- Settings must support **scoped overrides, layered within a module**: a setting can carry a
  module-wide default (`appl_id`), a further override for a specific user group within that
  module (`appl_id` + `group_id`), and a further override for a specific user within that
  module (`appl_id` + `user_id`) — resolved to one effective value at read time. (`appl_id` is
  a legacy column name carried over from a prior multi-client system, where it represented the
  client/application being served. In this platform's tenant-per-DB architecture that role is
  already handled by DB isolation, so `appl_id` is repurposed to mean **module** — the same
  code space as `tenant_modules.module_code`, §3A.)
- The same underlying mechanism must double as a **generic mini-master/enum table** — small,
  static lookup lists (a handful of rows, rarely if ever changed) should not require a
  dedicated migration + model + admin screen each; a couple of generic numeric (`num1`,
  `num2`) and string (`str1`, `str2`) payload columns plus a `note` field should cover the
  common case.
- Must formalize the already-referenced serial-numbering mechanism (`config_snums`,
  `ConfigSnumService::next()`) and the already-referenced menu/group/rights authorization
  infrastructure (`menu.perm:MENU_CODE`), since other modules' specs assume both exist.
- Must sit **beneath** every other module in the dependency graph — `SYSCONFIG` cannot depend
  on WNE, DMS, CRM, or anything else, since those modules (and their specs) assume `SYSCONFIG`
  is already available to them.
- Multi-tenant aware, same DB-per-tenant isolation as every other module — no `tenant_id`
  column (per `CLAUDE.md` §4/§7); `SYSCONFIG` lives inside each tenant DB, so its data is
  naturally per-tenant already.

# 2. Goals

> Designated features. MVP-first — this module blocks every other module conceptually, so a
> correct, minimal core matters more than a fully-built admin experience on day one.

**MVP (ship first — this is prerequisite infrastructure, not an optional add-on)**
- **Module Activation** (`tenant_modules`) — a tenant-DB toggle layer sitting on top of the
  existing central-DB entitlement mechanism (`tenants.plan` + `config/tenant_modules.php` +
  `TenantFeatureService`, already marked resolved in `CLAUDE.md` §11). This is a UX/visibility
  switch, never a second entitlement gate — see §3A.
- **Config Consts Engine** (`config_consts`) — the single mechanism serving general settings,
  module-level settings, and small mini-master/enum lookups, with `appl_id`/`group_id`/
  `user_id` scoped overrides resolved to one effective value. See §3B/§3C/§3E.
- **Config Serials Engine** (`config_snums`) — formalizes the atomic running-number mechanism
  already referenced in `ARCHITECTURE.md` §3.2.A and used by Legal's case-code generator. See
  §3D.
- **Menus, Groups & Access Rights** — formalizes the schema behind the `menu.perm:MENU_CODE`
  middleware `CLAUDE.md` §4 already references, and introduces `groups` as the one shared
  concept used both for menu-permission trustee *and* as a scope dimension for `config_consts`
  overrides. See §3F.
- **Config Audit Log** — append-only log of every settings/serial/activation change, same
  immutable-audit posture every other module in this platform already applies to its own
  sensitive data. See §3G.

**Future Version (explicitly deferred — do not build now)**
- **Central entitlement override table** (`central.tenant_module_grants`) — per-tenant
  overrides on top of plan-tier defaults (e.g. comping a beta feature, time-boxing a trial of
  AIInsight for one tenant) without a code deploy. Purely additive over the existing
  `config/tenant_modules.php` mechanism — build only once a real commercial need for
  per-tenant overrides (beyond plan tier) shows up.
- **Regulatory-style change control** — routing a sensitive const's change through a WNE
  approval workflow (`workflow_code = sysconfig.const_change_approval`) before it takes
  effect, for a tenant that wants a second pair of eyes before a threshold flips in
  production. Mirrors Payroll's optional Regulatory Rule activation workflow
  (`PAYROLL_SPECS.md` §3B) — same reuse-WNE pattern, not new approval logic.
- **Diff/preview UI** for a new versioned const value (compare proposed vs. currently active,
  before saving) — useful, not blocking; the underlying `effective_date` column (§3B) already
  supports it without a schema change later.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules,
> database design.

## 3A. Module Activation (Entry)

**Purpose:** a tenant-facing on/off switch for modules they're already entitled to — pure
visibility control, never a second entitlement gate.

- Fields: `module_code` (from the fixed platform module list — the same codes as the schema
  list in `CLAUDE.md` §7A: `WNE`, `DMS`, `CRM`, `SCHEDULE`, `INVENTORY`, `ACCOUNTING`,
  `PURCHASE`, `SALES`, `HCM`, `PAYROLL`, `PERFORMANCE`, `AIINSIGHT`, `LEGAL`), `is_active`
  toggle, `activated_at`/`activated_by` (auto-set), `notes`.
- List view: one row per platform module, showing the tenant's **entitlement** (read-only,
  sourced from central `tenants.plan` via `TenantFeatureService`) alongside the tenant's own
  `is_active` toggle — an admin sees both "are we paying for this" and "do we currently want
  it visible," and can never turn on something the tenant isn't entitled to.

**Rules / logic**
- **Effective visibility** (sidebar/menu, route access) = `entitled` (central) **AND**
  `is_active` (this table). Entitlement is always the hard ceiling; this table can only
  narrow it further, never widen it.
- **Default is opt-out, not opt-in**: if no row exists for a `module_code` the tenant is
  entitled to, it's treated as active — so a newly-entitled module (e.g. a plan upgrade)
  appears immediately without requiring a seed row here.
- Deactivating a module hides its sidebar/menu entries and blocks new records at the routing
  layer (the existing `module:CODE` middleware, `CLAUDE.md` §4) but never touches existing
  data — same non-destructive posture as every other module's deactivation/archive pattern.

## 3B. General & Module Settings Console (`config_consts`)

**Purpose:** the single mechanism serving both "general tenant-wide settings" and
"module-level settings," with optional scoped overrides.

- Fields: `appl_id` (nullable — **module scope**, the same code space as
  `tenant_modules.module_code`, §3A; a plain code, not itself a FK'd master table, since it's
  a small controlled set already enumerated elsewhere — legacy name from a prior system where
  it meant "client"; repurposed here since tenant isolation is already the DB boundary),
  `group_id` (nullable, FK → `SYSCONFIG.groups.id`, §3F — **user group** override, meaningful
  in combination with `appl_id`: "this group, within this module"), `user_id` (nullable, FK →
  the tenant's `users.id` — individual-user override, meaningful in combination with
  `appl_id`: "this user, within this module"), `const_group` (the
  logical namespace of the setting — a module code like `LEGAL`, or `SYSTEM` for
  platform-wide settings), `group_code` (the specific key within that namespace, e.g.
  `CASE_PREFIX`, `DEFAULT_LOCALE`), `value` (text — the scalar setting's value, cast per
  `value_type` in `ConfigService`), `value_type` (`text`/`number`/`bool`/`date` — VARCHAR +
  CHECK, not a native Postgres enum, matching the platform's own established "status/type
  fields as VARCHAR + CHECK" convention), `num1`/`num2` (nullable numeric payload slots),
  `str1`/`str2` (nullable string payload slots), `note` (free text), `seq` (display/sort
  order), `effective_date` (nullable — for a simple setting that changes on a schedule),
  `is_active`.
- List view: filterable/grouped by `const_group`, so an admin browsing Legal's settings never
  wades through AIInsight's. Row entry adapts its visible fields to `value_type` — a scalar
  setting shows one input matching its type; a row being used as an enum member (§3C) instead
  surfaces the `num1`/`num2`/`str1`/`str2`/`note` fields.
- To add a scoped override for an existing setting, an admin adds a **new row** with the same
  `const_group`/`group_code` but a non-null `appl_id`/`group_id`/`user_id` — never edits the
  global row in place.

**Rules / logic**
- Uniqueness enforced on `(appl_id, group_id, user_id, const_group, group_code)` — exactly one
  row per scope combination, no ambiguous duplicates.
- Every write is logged to `config_audit_logs` (§3G) — a flipped const can silently change
  business behavior platform-wide (per `LEGAL.URGENT_SETS_PENDING`'s own example in
  `ARCHITECTURE.md`), so it must be reconstructable who changed what, when, and from/to what
  value.
- Deactivating (`is_active = false`) is the default removal path, not delete — same
  non-destructive principle used everywhere else in this platform.
- Same "ponytail: single text `value` column, cast in the service layer" simplicity already
  established for `CUSTOMFIELDS.field_values` (`ARCHITECTURE.md` §1.2) — the ceiling, if ever
  needed, is typed columns or JSONB, not built now.

## 3C. Mini Master / Enum Management (same table, enum-lens view)

**Purpose:** manage small, static lookup lists as data instead of a hardcoded PHP `enum`, for
lists genuinely small enough (a handful of rows, changes rarely if ever) that a dedicated
table+migration+model would be overkill.

- Same `config_consts` rows: `const_group` acts as the enum's namespace (e.g.
  `const_group = 'GENDER'`), each member is one row with `group_code` as its stable code
  (`M`/`F`), `str1` as its display label, `str2` as an optional short form/abbreviation,
  `num1`/`num2` as optional numeric attributes (e.g. a sort weight or a multiplier), `note`
  for an admin-facing description, `seq` for display order.
- UI presents this as a simple ordered list editor (add row / reorder / deactivate) — a
  friendlier lens over the exact same table and engine as §3B, not a separate schema.
- `ConfigService::getGroup(constGroup)` (§3E) is the single integration point a dropdown/select
  component calls to render options, regardless of whether the calling code thinks of the data
  as "a setting" or "an enum."

**Rules / logic — explicit scope boundary (important, to prevent scope creep later):**
- This mechanism is for **small, low-cardinality, few-attribute, rarely-changing** lists only.
  A lookup that needs more attributes than `num1`/`num2`/`str1`/`str2`/`note` provide, needs to
  participate in a real foreign-key relationship, needs many rows, or grows/changes through
  ordinary business use stays a proper tenant-editable table in its own module's schema —
  exactly as already correctly specced (`CRM.partner_role_types`, `LEGAL.deed_types`,
  `HCM.leave_types`, `INVENTORY.adjustment_reasons`, and every other tenant-editable lookup
  table across the platform). `config_consts`-as-enum replaces a hardcoded PHP `enum`, not a
  module's own first-class master table.
- This is likewise **not** where Payroll's or Accounting's versioned statutory rate tables
  belong (PTKP brackets, TER rates, BPJS percentages, tax brackets) — those are genuinely
  multi-column, tightly-versioned, calculation-critical tables and correctly stay in their
  owning module's schema (`PAYROLL.ter_rate_brackets`, `ACCOUNTING.tax_codes`, etc.), per
  those modules' own specs. `effective_date` here exists for simple single-value settings that
  happen to change on a schedule (e.g. a flat reminder lead-time), not to absorb those tables.

## 3D. Serial Number Engine (`config_snums`)

**Purpose:** formalize the atomic running-number mechanism `ARCHITECTURE.md` §3.2.A already
describes and Legal's `PrefixedCaseCodeGenerator` already uses.

- `ConfigSnumService::next(snumCode): string` — atomic `SELECT ... FOR UPDATE` + increment.
- Fields: `snum_code` (unique, e.g. `LEGAL_CASE_LASTID`), `description`, `last_cnt` (current
  counter value), `wrap_high` (nullable — wrap-around ceiling, per the existing "wrap at
  `wrap_high`" behavior referenced in `ARCHITECTURE.md`), `padding_length` (zero-pad width),
  `reset_rule` (`never` / `yearly` / `monthly` — VARCHAR + CHECK, resets the counter at a
  period boundary).
- Admin screen (`/config/serials`, already referenced in `ARCHITECTURE.md` §3.2.A): list +
  manual counter correction — a manual override of a running serial is logged (§3G), never a
  quiet edit.

**Rules / logic**
- Locking scope is per `snum_code` row — two concurrent requests for the same code never
  receive the same number, the same concurrency discipline Inventory's costing layers and
  Legal's protocol numbering already apply elsewhere in this platform.
- **Not** a replacement for a module's own composite-scoped ledger numbering.
  `LEGAL.protocol_entries.sequence_number` is gap-free within `(book_id, year)` — a narrower,
  legally significant scope that correctly stays inside `LEGAL`'s own transaction, locked on
  its own `protocol_books` row (`LEGAL_SPECS.md` §5), not routed through this generic engine.
  `config_snums` is for simple tenant/module-wide running numbers only.

## 3E. Config Resolution Engine (Service)

**Purpose:** the one reusable service every other module calls to read a setting — the actual
runtime consumption API behind §3B/§3C.

- `ConfigService::get(constGroup, groupCode, ?applId, ?groupId, ?userId): mixed` — resolves
  the most specific matching row via **two-tier precedence**:
  1. **Module tier** — prefer rows where `appl_id` matches the caller's current module; if
     none exist for that `(const_group, group_code)`, fall back to rows where `appl_id IS
     NULL` (a platform-wide default not scoped to any module).
  2. **Within that module tier**, prefer: `user_id` match > `group_id` match > neither
     (the plain module-level default).
  - Concretely, for `LEGAL.SIGNING_REMINDER_DAYS`: a row with `appl_id = 'LEGAL'` and
    `group_id`/`user_id` both null sets the value for everyone in the Legal module; a second
    row with `appl_id = 'LEGAL', group_id = <Notaries team>` overrides it just for that group;
    a third row with `appl_id = 'LEGAL', user_id = <specific PPAT>` overrides it just for that
    person — all three rows coexist, and `get()` returns whichever is most specific for the
    user asking.
  - Returns the cast `value` for a scalar setting, or a keyed accessor into
    `num1`/`num2`/`str1`/`str2`/`note` for an enum member lookup.
- `ConfigService::getGroup(constGroup, ?applId, ?groupId, ?userId): Collection` — every active
  member of a `const_group`, scope-resolved per row where overrides exist, ordered by `seq` —
  what a dropdown/select component calls (§3C).
- `ConfigService::set(...)` — the only write path; always logs to `config_audit_logs` (§3G).
- **Caching**: results cached in Redis, keyed
  `tenant:{db}:config:{constGroup}:{applId}:{groupId}:{userId}`, invalidated on any `set()`
  call touching that `const_group`. This is read-heavy, write-rare data (a value like
  `LEGAL.URGENT_SETS_PENDING` may be checked on every case save) — exactly the shape Redis
  caching (already provisioned per `CLAUDE.md` §3) exists for.

**Rules / logic**
- Resolution never partially merges two rows (e.g. taking `value` from a group-level override
  and `note` from the module-default row) — exactly one winning row, in full, per the two-tier
  precedence above. Predictable over clever.
- `group_id`/`user_id` set **without** `appl_id` (i.e. `appl_id IS NULL`) is a valid, if less
  common, combination — a platform-wide override for a group or user that isn't module-scoped
  at all. The two-tier logic handles it automatically (it simply lives in the "no module
  match" fallback tier), no special-casing needed.

## 3F. Menus, Groups & Access Rights (Trustee)

**Purpose:** formalize the schema behind infrastructure `CLAUDE.md` §4 already references as
existing (`menu.perm:MENU_CODE` middleware) — no new authorization *behavior*, just a concrete
schema for Claude Code to build and extend against, since every other module's spec assumes
permission checks work without ever pointing at a schema.

- `SYSCONFIG.menus` — hierarchical tree (`parent_menu_id`, self-referencing — same pattern as
  CRM's `parent_partner_id`), `code`, `label`, `route`, `icon`, `module_code` (which module's
  sidebar section this belongs to — drives the activation hide/show behavior in §3A).
- `SYSCONFIG.groups` — roles/teams (`name`, `description`). **The same concept used both for
  menu-permission trustee below and as the `group_id` scope dimension on `config_consts`
  (§3B/§3E)** — one "group" notion serves both purposes, avoiding a second, parallel concept
  of "group" the same way this platform already avoids parallel AR ledgers or parallel
  notification paths elsewhere.
- `SYSCONFIG.group_members` — pivot, `group_id` × `user_id`.
- `SYSCONFIG.menu_rights` — `menu_id` × `group_id`, C/R/U/D flags — the trustee table the
  existing `menu.perm:MENU_CODE` middleware checks against.
- Admin screens: menu tree CRUD, group CRUD + member assignment, a rights matrix (menu ×
  group grid, per `DESIGN.md`'s component inventory).

## 3G. Config Audit Log

- `SYSCONFIG.config_audit_logs` — append-only, one row per write to `config_consts` /
  `config_snums` / `tenant_modules` (`action`: `created` / `updated` / `deactivated` /
  `serial_corrected`), actor, timestamp, before/after value snapshot — same immutable-audit
  posture as `dms.access_logs`, `wne.wrkflow_audit_logs`, `acct.audit_logs`, and every other
  append-only log across this platform.
- No update/delete permitted on this table at the app layer, same rule as everywhere else.

# 4. Storage

**Database (schema `SYSCONFIG`, tenant DB — consistent with `CLAUDE.md` §7A; no `tenant_id`
column, DB-per-tenant is the isolation boundary):**

**Master / config tables**
- `SYSCONFIG.tenant_modules` — module activation toggle (§3A).
- `SYSCONFIG.config_consts` — settings + mini-master/enum engine (§3B/§3C).
- `SYSCONFIG.config_snums` — serial number generator (§3D).
- `SYSCONFIG.menus` — hierarchical menu tree (§3F).
- `SYSCONFIG.groups` — roles/teams (§3F) — shared by config scope override and menu trustee.
- `SYSCONFIG.group_members` — pivot, group × user (§3F).
- `SYSCONFIG.menu_rights` — trustee C/R/U/D, menu × group (§3F).

**Log tables**
- `SYSCONFIG.config_audit_logs` — append-only (§3G).

**Object file storage:** none required — this module has no documents of its own.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, but the **base of the dependency graph**. Unlike every
other Core module (WNE, DMS, CRM, Schedule, ...), `SYSCONFIG` has **zero dependency on any
other module, including WNE**. Other modules may optionally fire a `NotificationRequested`
into WNE when a sensitive const changes (if WNE is installed for the tenant), but `SYSCONFIG`'s
own operation — auth/permission checks, config resolution, serial generation — must never
require WNE, since WNE (and every later module) resolves its own consts/permissions through
this module. Keeping this one-directional avoids a circular foundational dependency.

**Build order correction (recommend amending `CLAUDE.md` §5):** `CLAUDE.md` §5's Core module
build order currently starts with WNE, but every module's spec already assumes
`config_consts`/`config_snums` exist (Legal's `CASE_PREFIX`/`URGENT_SETS_PENDING` example
predates this spec) and tenancy bootstrap itself needs `groups`/`menu_rights` for permission
middleware from day one. `CLAUDE.md` §4/§7 already lists `SYSCONFIG` first in the schema
structure — the build-order section in §5 just never caught up to that. Recommend `SYSCONFIG`
be built immediately after the design system, before WNE.

**Seeding:** `TenantFlavorSeeder` (already referenced in `ARCHITECTURE.md` §1.4) is where
per-tenant default `config_consts` rows get seeded at tenant provisioning — Firm A vs. Firm B
differing consts (`CASE_PREFIX`, `URGENT_SETS_PENDING`) is exactly this mechanism, already in
use before this module had a formal spec.

**Explicit non-goals (to prevent scope creep):**
- `config_consts` is for scalar settings and small static enumerations only — never a
  generalized replacement for a module's own tenant-editable master tables (role types, deed
  types, leave types, ...) or for a module's own versioned statutory rate tables (Payroll,
  Accounting). See the scope boundary in §3C.
- This module builds no UI for tenant *plan*/billing management — that remains the central-DB
  `tenants.plan` concern (`CLAUDE.md` §11); `tenant_modules` (§3A) only ever narrows what the
  plan already grants, never manages the plan itself.

**Value casting convention:** `value_type` (and every other status/type-like column in this
module — `reset_rule`, `action` on the audit log) is `VARCHAR` + `CHECK`, never a native
Postgres `ENUM` — matching this platform's own established convention (avoids a disruptive
type rewrite whenever a new value is needed, same reasoning already applied across every other
module in this codebase).

**Marketability note:** invisible infrastructure, but it's what makes every other module's own
"this is tenant-editable, never a hardcoded constant" promise actually true platform-wide —
worth knowing it exists as the mechanism behind that story, even though it's never demoed on
its own.

**Suggested build order for Claude Code:** 3F (menus/groups/rights — needed for any permission
check anywhere in the platform) → 3B + 3G together (config_consts CRUD, audited from the first
write, not bolted on later) → 3E (resolution service + Redis caching — this is what unblocks
every other module's spec that already references reading a const) → 3D (config_snums) → 3A
(tenant_modules) → 3C (enum-lens UI over the same 3B table, cheap once 3B exists) — **ship
here** — every other Core module's own build can now assume `SYSCONFIG` is real, exactly as
their specs already do.
