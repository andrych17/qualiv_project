# CustomFields Module
## Entity-Attribute-Value (EAV) Engine for Tenant-Specific Fields — Foundational Core Module (no standalone story; every other module depends on it)

# 1. Backgrounds

> Pain point and business value.

Every tenant on this platform eventually wants a field the core schema doesn't have: a law
firm wants "Bar Number" on a Contact, a property manager wants "Unit Number" on that same
Contact, a Legal case needs "Court Register" and "Hearing Date," an Inventory item needs a
"Fragile" flag. `CLAUDE.md` §2's own customization ladder puts this at rung 3 — "prefer lower
rungs first" — directly below Constants and Serials, and above writing bespoke per-tenant
logic. Left unsolved as a shared mechanism, every module ends up solving it differently:

- Each module invents its own "extra fields" story — a JSON blob column here, a set of
  nullable columns there — no consistent validation, no consistent admin UX, no consistent
  read/write API.
- Per `ARCHITECTURE.md` §3.4's own Do/Don't table, the anti-pattern this module exists to
  prevent is spelled out explicitly: *"Add nullable columns to `LEGAL.cases` for one firm."*
  Without a shared EAV mechanism, that's exactly what an under-pressure solo dev reaches for —
  one nullable column becomes five, becomes fifteen, becomes a table only one firm's fields
  actually populate.
- Nearly every `*_SPECS.md` already written for this platform promises tenant-configurability
  for at least one of its entities — CRM's partners/leads/tickets, DMS's documents, Legal's
  deeds/matters/land objects, Inventory's products, Payroll's components/runs, and more — and
  every one of those promises assumes `CUSTOMFIELDS.field_defs`/`field_values` already exist
  and behave a specific way. Without a formal spec, that promise has no enforced shape
  platform-wide — the same risk `SYSCONFIG_SPECS.md` was written to close for consts, serials,
  and permissions.
- `ARCHITECTURE.md` already documents a **working example** (Legal's `court_register`/
  `hearing_date`/`priority` custom fields, wired through `CustomFieldService` and
  `CustomLogicEngine`) but stops short of a formal module spec — Claude Code has a pattern to
  imitate, not a documented contract to build against when the next module needs the same
  thing.
- Admin CRUD for field definitions was, for a while, flagged as **not yet built** in
  `ARCHITECTURE.md` §3.1 — every tenant-specific field required Simon to hand-seed a database
  row, which doesn't scale past one paying tenant and left a genuine selling point on the table
  ("add your own intake fields, no support ticket needed"). **Shipped** as of the §3A build
  below (`/config/fields`, gated by `menu.perm:CONFIG_FIELDS`) — `ARCHITECTURE.md` §3.1 has been
  updated to match.

**Client requirements:**
- One reusable mechanism for "this entity has tenant-specific extra fields," usable by any
  Core or Vertical module without a dedicated migration per field.
- Must support the field types already established in `ARCHITECTURE.md` (`text` / `number` /
  `date` / `select`), with room to add more (`boolean`, `textarea`, `multi-select`) as
  additive, non-breaking Future Version work.
- Required-field validation, select-option validation, and type casting must be centralized —
  not re-implemented per module. `ARCHITECTURE.md` §2.3's own "Wire pattern" already shows the
  intended flow (`formPayload` → `validateAndNormalize` → persist → `sync`); this spec
  formalizes it as the contract every module builds against.
- Must support the same "beforeSave" custom-logic hook already demonstrated for Legal
  (`CustomLogicEngine::beforeSave` reading `SYSCONFIG.config_consts` plus custom field values
  to mutate the core payload) as a generic, per-entity extension point — not Legal-specific
  code quietly living inside a Core module.
- An **admin-facing CRUD screen** for field definitions, gated by `SYSCONFIG`'s menu/rights
  trustee (`menu.perm:MENU_CODE`, `CLAUDE.md` §4), so adding a tenant-specific field becomes a
  data-entry task, not a SQL/seed task — this closes the open item `ARCHITECTURE.md` §3.1
  explicitly flags.
- Multi-tenant aware, same DB-per-tenant isolation as every other module (no `tenant_id`
  column, per `CLAUDE.md` §4/§7) — `CUSTOMFIELDS` lives inside each tenant DB, so a field one
  tenant defines is invisible to another by construction, not by a query filter.
- Must sit **beneath** every Core and Vertical module in the dependency graph, alongside
  `SYSCONFIG` — every other module's spec already assumes `CUSTOMFIELDS.field_defs`/
  `field_values` exist and behave per `ARCHITECTURE.md` §1.2/§2.3.
- Ponytail-first: a single `value` column (cast in the service layer), never typed columns per
  field type — the exact "ponytail: single `value` column. Ceiling: typed columns / JSONB if
  reporting needs heavy filters" posture `ARCHITECTURE.md` §1.2 already states for this table.

# 2. Goals

> Designated features. MVP-first — this module blocks every other module's tenant-customization
> story, so a correct, minimal core (plus the admin screen that closes the "not yet" gap)
> matters more than a rich reporting/rules layer on day one.

**MVP**
- **Field Definition Management (Admin Entry).** CRUD over `field_defs` — entity type, field
  type, label, options, required/order/status — gated by the existing `SYSCONFIG` menu/rights
  trustee, resolving `ARCHITECTURE.md`'s former "Admin CRUD for defs: not yet" open item.
  **Shipped** (§3A).
- **Custom Field Value Capture (shared Vue component).** `CustomFieldInputs.vue`, one reusable
  component embedded in any module's Create/Edit page, rendering the right input per field
  type from a single `formPayload()` call (§3B).
- **Validation & Normalization Engine.** `CustomFieldService::validateAndNormalize()` —
  required-field checks, select-option checks, type casting, centralized so no module
  re-implements it (§3C).
- **Persistence / Sync Engine.** `CustomFieldService::sync()` — upserts `field_values` inside
  the same transaction as the owning module's core-row save (§3D).
- **Read / Form Payload Engine.** `CustomFieldService::formPayload()` — the one call a
  Controller makes before rendering a form, returning defs merged with current values (§3E).
- **Custom Logic Engine.** `CustomLogicEngine::beforeSave()` — the generic version of Legal's
  existing `URGENT_SETS_PENDING` example, reading `SYSCONFIG.config_consts` + custom values to
  mutate a core payload before persistence, registered per entity type (§3F).
- **Entity Registration Convention.** The (deliberately lightweight) pattern a new module
  follows to "turn on" custom fields for one of its entities — no CustomFields-side code
  change required, purely data-driven (§3G).
- **Field Definition Audit Log.** Append-only log of every `field_defs` write — same
  immutable-audit posture every other sensitive/structural table in this platform already
  uses (§3H).

**Future Version (explicitly deferred — do not build now)**
- **Typed-column / JSONB ceiling** for entities whose custom fields need heavy reporting
  filters — the ceiling `ARCHITECTURE.md` §1.2 already names explicitly; not needed until a
  specific tenant's reporting need actually demands it.
- **Conditional field visibility / dependency rules** (show Field B only if Field A = X) — a
  real UX nicety, not blocking for MVP's flat field lists.
- **Multi-select / multi-value fields** — would need a child values table instead of the
  single `value` column; deferred until a concrete need appears, since it changes the shape of
  `field_values`, not just adds a field type.
- **Versioned field definitions** (an effective-dated pattern like Payroll's/Accounting's
  statutory rate tables) — custom field definitions change far less often and with far lower
  compliance stakes than a tax bracket; not worth the complexity now.
- **Field-level permissions** (visible/editable per role, not just per entity via the coarse
  menu.perm gate) — a real RBAC refinement, deferred alongside every other module's
  "folder/document-level flag now, fine-grained ACL later" MVP bias (same posture DMS applies
  to its own folder access flag).
- **Cross-entity custom-field reporting / query builder** — a natural fit for **AIInsights
  Core**'s "ask your data" pattern once that module ships, rather than a bespoke report
  builder built here first.
- **Field definition import/export (JSON)** — cloning a tenant's custom-field configuration
  onto a new tenant (e.g. "give Firm B the same intake fields Firm A already has") — useful
  once there's a second Legal-vertical tenant to make it worth building.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules,
> database design.

## 3A. Field Definition Management (Entry) — Shipped

**Purpose:** the admin screen that closes `ARCHITECTURE.md`'s former "not yet" gap — lets a
tenant admin (or Simon) add a custom field without a SQL/seed change.

**Built at:** `app/Modules/CustomFields/{Models/FieldDef.php,Models/FieldDefAuditLog.php,
Services/FieldDefService.php,Controllers/FieldDefController.php,
Requests/{Store,Update}FieldDefRequest.php}`, routes in `app/Modules/SysConfig/Routes/web.php`
under `menu.perm:CONFIG_FIELDS`, menu seed row in `SysConfigSeeder.php`, Vue pages at
`resources/js/Pages/Config/Fields/{Index,Create,Edit}.vue`. Covered by
`tests/Feature/CustomFieldDefCrudTest.php`.

- Fields: `entity_type` (the registration key an owning module already uses internally, e.g.
  `legal_case`, `inventory_item` — free text in the DB, but the UI offers a dropdown built
  from distinct values already in use, to discourage typos rather than block them),
  `module_code` (nullable lookup, same code space as `SYSCONFIG.tenant_modules.module_code` —
  used purely for admin-screen filtering/scoping, e.g. "show me all Legal fields," and to gate
  who can edit which fields via the menu/rights trustee), `code` (stable key, e.g.
  `court_register`), `label` (UI label), `field_type` (`text` / `number` / `date` / `select` —
  `VARCHAR` + `CHECK`, not a native Postgres enum, matching this platform's own established
  "status/type as VARCHAR + CHECK" convention), `options` (JSON, select only — `[{label,
  value}]`), `is_required`, `seq` (form order), `status` (`active`/`inactive`).
- List view: filterable/grouped by `module_code` then `entity_type` — the same
  filter-by-namespace UX `SYSCONFIG.config_consts`'s own admin list already uses
  (`SYSCONFIG_SPECS.md` §3B), so the two "tenant-editable configuration" screens in this
  platform feel like one family of UI, not two.
- Row entry: a simple form, options editor (add/remove label-value pairs) shown only when
  `field_type = select`.

**Rules / logic**
- Uniqueness enforced on `(entity_type, code)`, per `ARCHITECTURE.md` §1.2.
- Deactivating (`status = inactive`) is the default removal path, never a hard delete — a
  deactivated field def stops appearing on new forms but its historical `field_values` remain
  readable, same non-destructive posture as every other module in this platform.
- Every write is logged to `field_def_audit_logs` (§3H) — a field definition change can affect
  every record of that `entity_type` platform-wide, so it needs the same reconstructability as
  a `SYSCONFIG` const change.

## 3B. Custom Field Value Capture (Component)

**Purpose:** one reusable Vue component, embedded in any module's Create/Edit page — never
reimplemented per module, per `ARCHITECTURE.md` §2.1's module map
(`resources/js/Components/forms/CustomFieldInputs.vue`, composed into e.g.
`resources/js/Pages/Legal/Cases/{Create,Edit}.vue`).

- Receives the output of `formPayload()` (§3E) as props — active field defs for the entity
  type, ordered by `seq`, merged with current values if editing — and renders one input per
  def.
- Input type maps to `field_type` through the existing `DESIGN.md` primitive set: `text` →
  Input, `number` → Input (numeric), `date` → Date/time picker, `select` → Select/Combobox.
- Client-side required-field feedback mirrors the server-side rule for responsiveness, but
  server validation (§3C) is always authoritative — client-only validation is never trusted,
  same discipline every Form Request in this codebase already follows (`CLAUDE.md` §6).

## 3C. Validation & Normalization Engine

`CustomFieldService::validateAndNormalize(entityType, customFieldsInput): array`

- Loads active `field_defs` for `entityType`, checks every `is_required` field is present,
  checks `select` values against the def's `options`, casts each value per `field_type`
  (number → numeric, date → date, text/select → string), and returns a normalized array keyed
  by `field_def_id`.
- Called by the owning module's own Service (e.g. `LegalCaseService::create`/`update`) —
  CustomFields is called *into*, it never reaches into a calling module's own domain logic,
  same one-way dependency rule as everywhere else in this platform (`CLAUDE.md` §2/§9).
- A missing required field, or a `select` value outside the def's `options`, fails validation
  with a field-specific message surfaced back through the calling module's own Form Request —
  same "errors state what happened and what to do next" voice as `DESIGN.md` §5.

## 3D. Persistence / Sync Engine

`CustomFieldService::sync(entityType, entityId, normalizedValues): void`

- Upserts `field_values` rows (`field_def_id`, `entity_type`, `entity_id`, `value`) per the
  unique constraint in `ARCHITECTURE.md` §1.2 (`field_def_id, entity_type, entity_id`), and
  removes rows for any def not present in the submitted payload (a field explicitly cleared
  back to blank) — never leaves a stale value silently attached to a record.
- Executed inside the **same database transaction** as the owning module's core-row
  INSERT/UPDATE, matching the sequence `ARCHITECTURE.md` §2.4 already diagrams
  (`Svc→DB: INSERT/UPDATE LEGAL.cases` immediately followed by `Svc→CF: sync`) — a custom-field
  write can never partially succeed against its own record.

**Rules / logic**
- Deleting a core entity's row does not cascade-delete its `field_values` in MVP — see §5 for
  the reasoning and the Future Version note. Most modules in this platform prefer
  soft-delete/deactivate over hard delete anyway (per each module's own spec), which limits how
  often this actually matters today.

## 3E. Read / Form Payload Engine

`CustomFieldService::formPayload(entityType, entityId = null): array`

- Returns every active `field_defs` row for `entityType` (ordered by `seq`), merged with the
  current `field_values` for `entityId` if editing (empty/default if creating new) — the
  single call a Controller makes before rendering a form, per the wire pattern in
  `ARCHITECTURE.md` §2.3 (`Controller → formPayload(entity_type, id?)`).
- Read-only, safe to call on every page load; no state mutation.

## 3F. Custom Logic Engine (beforeSave hooks)

`CustomLogicEngine::beforeSave(entityType, data, customValues): array`

- The generalized version of Legal's existing example
  (`ARCHITECTURE.md` §3.2.B — `LEGAL.URGENT_SETS_PENDING` const + `priority = urgent` custom
  field + `status = open` core field → force `status = pending`): reads
  `SYSCONFIG.config_consts` via `ConfigService::get()` (`SYSCONFIG_SPECS.md` §3E, always a
  **read**, never a write, into `SYSCONFIG`) plus the just-validated custom field values, and
  can mutate the core payload before it's persisted.
- Rules are registered per `entityType`. MVP scope is flat conditional logic — a small
  match/if-chain per entity type — matching `ARCHITECTURE.md`'s own stated posture for this
  exact engine: *"ponytail: flat ifs in engine. Ceiling: pluggable Rule classes when rules
  multiply."* A Strategy/Rule-class refactor is Future Version, only once a given entity
  type's rule count genuinely grows unwieldy — not a day-one abstraction.

**Rules / logic**
- `CustomLogicEngine` never queries or reaches into a calling module's own tables — its only
  inputs are the `entityType` string, the core payload, and custom field values. This is what
  keeps CustomFields' zero-knowledge-of-Vertical (and zero-knowledge-of-any-other-Core-module)
  posture intact — `ARCHITECTURE.md` §2.2 states this explicitly: *"CustomFields (Core) ...
  Must not import Legal models."*

## 3G. Entity Registration Convention

**Purpose:** how a new module "turns on" custom fields for one of its entities — deliberately
not a formal registration step, to keep onboarding a new entity type cheap.

- There is **no** `entity_types` master/lookup table to maintain. `entity_type` is a plain
  string constant the owning module's own Service layer already uses internally (e.g.
  `legal_case`, `inventory_item`, `hcm_employee`), exactly matching
  `ARCHITECTURE.md` §1.2's `field_defs.entity_type` column. Turning on custom fields for a new
  entity is two steps: (1) seed `field_defs` rows for that `entity_type` (via the Admin screen,
  §3A, or a seeder for initial defaults), and (2) call `formPayload()` /
  `validateAndNormalize()` / `sync()` from the owning module's own Service, per the wire
  pattern in §2.3/§2.4 of `ARCHITECTURE.md`. No change to CustomFields' own code is required to
  onboard a new `entity_type` — this is deliberately data-driven, not registry-driven, matching
  the low-ceremony bias the rest of this customization ladder rung already has.
- A `HasCustomFields` trait (or an equivalent thin Service helper) is the suggested
  implementation shortcut for a module's own model/service — not a hard requirement, just the
  boring, consistent way to avoid re-writing the same three calls per module. Worth formalizing
  once at least two modules have actually used the pattern, so the trait reflects real usage
  rather than a speculative shape (see §5 suggested build order).

## 3H. Field Definition Audit Log

- `CUSTOMFIELDS.field_def_audit_logs` — append-only, one row per write to `field_defs`
  (`action`: `created` / `updated` / `deactivated`), actor, timestamp, before/after value
  snapshot — same immutable-audit posture every other module in this platform already applies
  to its own structural/sensitive changes (`dms.access_logs`, `wne.wrkflow_audit_logs`,
  `acct.audit_logs`, `sysconfig.config_audit_logs`). No update/delete permitted on this table
  at the app layer, same rule as everywhere else.

---

# 4. Storage

> Tables and object files used by this module. Schema: `CUSTOMFIELDS` (per tenant DB, per
> `CLAUDE.md` §7A). No `tenant_id` column — DB-per-tenant is the isolation boundary, same as
> every other module.

**Core tables**
- `CUSTOMFIELDS.field_defs` — what fields exist per entity: `id`, `uuid` (external-facing),
  `entity_type`, `module_code` (nullable, admin filter/scope only), `code`, `label`,
  `field_type` (`text`/`number`/`date`/`select`), `options` (JSON, select only), `is_required`,
  `seq`, `status`, unique on `(entity_type, code)`.
- `CUSTOMFIELDS.field_values` — values per entity row: `id`, `field_def_id` (app-enforced link
  to def, per `ARCHITECTURE.md` §1.2), `entity_type`, `entity_id` (the owning row's PK — not
  an enforced FK, since the target table varies by `entity_type`, same app-enforced-reference
  discipline used platform-wide for `subject_type`/`subject_id` pointers), `value` (text,
  cast per `field_type` in the service layer), unique on
  `(field_def_id, entity_type, entity_id)`.

**Log table**
- `CUSTOMFIELDS.field_def_audit_logs` — append-only (§3H).

**Object file storage:** none — this module stores structured data only, no documents. Any
module that wants to attach a *file* as a custom field's value should route it through **DMS**
(`DocumentService::attach()`, `subject_type` = the owning record) rather than storing a file
reference as a `field_values.value` string — CustomFields does not implement file handling,
same reuse discipline as everywhere else in this platform.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, at the **base of the dependency graph** alongside
`SYSCONFIG` — `app/Modules/CustomFields/` (`Models/FieldDef.php`, `Models/FieldValue.php`,
`Services/CustomFieldService.php`, `Services/CustomLogicEngine.php`), per `ARCHITECTURE.md`
§2.1's module map. CustomFields has **zero dependency** on WNE, DMS, CRM, or any other module,
with one narrow exception: `CustomLogicEngine` **reads** (never writes) `SYSCONFIG.config_consts`
via `ConfigService::get()` — the same read-only posture every other module already has toward
`SYSCONFIG` (`SYSCONFIG_SPECS.md` §5's "zero dependency ... including WNE" note applies here
identically, one level up the stack).

**Module boundary (straight from `ARCHITECTURE.md` §2.2, restated as this module's own
contract):**

| Layer | Owns | Must not |
|-------|------|----------|
| CustomFields (Core) | EAV tables + generic validation/persistence/logic engines | Import a Vertical or another Core module's models |
| Any owning module (Legal, CRM, Inventory, ...) | Domain service, its own core-table columns, calling the wire pattern below | Put its own EAV data under its own schema (that's exactly the anti-pattern this module exists to prevent) |
| `SYSCONFIG` | Consts/menus/rights that `CustomLogicEngine` reads | Know CustomFields exists |

**`entity_type` vs. `subject_type`/`subject_id` — a deliberate, easy-to-conflate distinction.**
Nearly every spec in this platform uses a `subject_type`/`subject_id` polymorphic pointer
(WNE's workflow instances, DMS's attachments, CRM's `svc_cases`, Sales's `so_hdrs`, and more)
to reference *a different record in a different module*, for loose cross-module linkage. This
module's `entity_type` looks similar (a loose string reference, no enforced FK) but solves a
different problem: it's a **registration key** an owning module chooses for *its own* record
type, resolved entirely within that module's own Service layer, to mean "this kind of record
accepts custom fields." The two conventions never point at each other and should not be
confused when reading another module's spec.

**The wire pattern (authoritative — every module implements this exact sequence, per
`ARCHITECTURE.md` §2.3/§2.4):**
```text
Controller
  → formPayload(entity_type, id?)
Service create/update
  → validateAndNormalize(custom_fields)
  → (optional) domain helpers (e.g. a code generator)
  → CustomLogicEngine::beforeSave
  → persist core row
  → sync(entity_type, id, values)
Service delete
  → deleteFor → delete core row
```

**Ceiling, restated from `ARCHITECTURE.md` §1.2:** `field_values.value` is a single nullable
text column, cast per `field_type` in the service layer — deliberately not typed columns. If a
tenant's reporting need eventually requires heavy filtering/sorting on one specific custom
field (e.g. "list every Legal case where `court_register` > X"), the ceiling is either
promoting that one field to a real column on the owning module's own core table (an ordinary
additive migration, same discipline every other module already applies to its own schema) or a
`JSONB` projection — not a redesign of this module.

**Hard-delete note:** MVP does not cascade-delete `field_values` when a core row is
hard-deleted. This is a deliberately low priority: almost every module in this platform prefers
soft-delete/deactivate over hard delete already (per each module's own spec — CRM never
hard-deletes a Partner, Legal never hard-deletes a Deed, HCM never hard-deletes an Employee), so
the orphan case is rare in practice. A cleanup job, or an additive on-delete-cascade once a
concrete hard-delete-capable module exists, is a cheap Future Version addition — not worth the
complexity today.

**Select options as JSON, not a child table:** same "ponytail" bias as the rest of this module
— a controlled-vocabulary table only earns its cost if options need translation,
usage-frequency ordering, or cross-tenant sharing, none of which are current requirements.

**Caching:** `field_defs` is read-heavy, write-rare — the same shape `SYSCONFIG.config_consts`
already caches (`SYSCONFIG_SPECS.md` §3E). `formPayload()` can reuse the identical Redis
convention, keyed `tenant:{db}:customfields:defs:{entity_type}`, invalidated on any
`field_defs` write for that entity type — a performance optimization, not required for MVP
correctness at expected data volumes.

**Suggested build-order correction (recommend amending `CLAUDE.md` §5, the same style of
correction `SYSCONFIG_SPECS.md` §5 already makes for itself):** `CLAUDE.md` §5 currently lists
CustomFields *after* WNE, DMS, CRM, and Schedule in the Core module build sequence — but DMS's
Metadata Management (`DMS_SPECS.md` §2 MVP: "tenant-defined custom fields, reusing the existing
`CUSTOMFIELDS` schema pattern") and CRM's Custom Fields (`CRM_SPECS.md` §2/§4) both already
assume `CUSTOMFIELDS.field_defs`/`field_values` exist as part of *their own* MVP ship. Recommend
CustomFields be built immediately after `SYSCONFIG` — the two foundational,
zero-downstream-dependency modules going in together, before WNE — so every subsequent Core
module's spec finds real infrastructure instead of assumed infrastructure.

**Every "reuses the existing CUSTOMFIELDS schema pattern" reference across every other
`*_SPECS.md` in this platform** (DMS, CRM, Legal, Inventory, Payroll, and others) is describing
exactly the mechanism specced here — there is no separate, per-module EAV implementation
anywhere in this codebase.

**Marketability notes**
- "Add your own intake fields, no code deploy, no support ticket" is a genuine, demoable
  differentiator for the same conservative legal-buyer audience `DESIGN.md` targets — once the
  Admin CRUD screen (§3A) ships, this closes `ARCHITECTURE.md`'s "not yet" gap and becomes a
  sellable configurability story, mirroring the same trust/configurability pitch `SYSCONFIG`'s
  tenant-editable consts already tell.
- Being invisible, foundational infrastructure (like `SYSCONFIG`) means this module is never
  demoed on its own — its marketability is what it *enables* in every other module's own demo
  ("yes, you can add your own field for that"), not a screen of its own.

**Suggested build order for Claude Code:** schema first (`field_defs` / `field_values` /
`field_def_audit_logs` — no UI required yet, just enough to unblock every other module's own
build) → 3C/3D/3E (`validateAndNormalize` / `sync` / `formPayload` — the trio every calling
module actually needs) → 3B (`CustomFieldInputs.vue`, the shared component) → 3F
(`CustomLogicEngine`, thin at first — one entity type's worth of rules, e.g. Legal's existing
`URGENT_SETS_PENDING` example, is enough to prove the pattern) → 3G (formalize the
`HasCustomFields` trait/registration convention once at least two modules have actually used
it, so it reflects real usage rather than speculation) → 3A (the Admin CRUD screen — not
blocking for the first module or two, since manual seeding works fine initially, but the
concrete deliverable that removes the SQL/seed workaround permanently — **shipped**) — then
revisit Future Version items (JSONB ceiling, conditional visibility, multi-select, versioned
defs) only once a real tenant need appears.
