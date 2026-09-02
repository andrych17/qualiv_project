# MES Module
## Core Shared Module — Manufacturing Execution System (Discrete/Assembly + Continuous/Process, one engine)

**Module category** (`CLAUDE.md` §2/§10): **Core**. MES has zero knowledge of any vertical — it
is a shared execution engine other tenants rent regardless of what they make. It is not
Platform-level (it lives in the tenant DB like every other Core module, not the central `nusaevo`
DB) and there is no case today for extracting it as a microservice (`CLAUDE.md` §2 criteria: no
fundamentally different scaling need, no non-PHP runtime requirement, no cross-product reuse
outside this monolith, no elevated-isolation data). It is now listed in `CLAUDE.md` §4/§5/§7A
(schema `MES`, sequenced after Performance in the Core build order — see there for why) and
unlocked on the `full` plan in `config/tenant_modules.php`; it is **not yet on `starter`,
`legal`, or `internal`**, since none of those tenants make anything — see §7 Open Items for what
still needs a real decision (a dedicated manufacturing plan/vertical) rather than the "everything
plan" placeholder it has today.

---

# 1. Backgrounds

> Pain point and business value.

Manufacturing tenants (a future vertical, or a plan add-on for any tenant that makes physical
goods) need to know **what actually happened on the floor**, not just what the ERP planned:

- ERP production orders describe *intent* (planned qty, planned dates). Without an execution
  layer, there is no record of actual start/stop times, actual material consumed, actual output,
  scrap, or who did what — so cost variance, delivery slippage, and quality escapes are
  discovered too late, if at all.
- Discrete (assembly) and continuous (process) manufacturing look structurally different —
  operations/work-centers/serial numbers vs. batches/recipes/process-parameters/lots — which
  tempts building two unrelated modules. That duplicates every shared concern (material
  consumption, quality, traceability, equipment, scheduling, dashboards) twice and doubles the
  maintenance burden for a solo developer.
- Material and lot/serial genealogy (which raw lot went into which finished lot/serial) is
  usually bolted on late, if at all — but it is the difference between a targeted recall and a
  blanket one, and increasingly a regulatory requirement (food, pharma, automotive).
- Standalone MES products are expensive, require separate integration to ERP inventory/quality/
  HR, and don't fit the tenant-isolated, plan-gated SaaS model this platform already has for
  every other module.

**Client requirements:**
- **One execution engine, two production models** — `assembly` (discrete) and `process`
  (continuous) share the same Production Order, event ledger, material consumption, output,
  scrap, quality, traceability, equipment, and dashboard concepts; only the unit of work differs
  (Operation vs. Phase) per §3's architecture.
- **Production event ledger**: every execution-significant action (start, pause, complete,
  material issued, output produced, parameter recorded, downtime, scrap, batch split/merge) is
  an immutable, timestamped event — the backbone for traceability, OEE, and audit.
- **Shop-floor operator UI**, not a repurposed ERP screen — large touch targets, current
  order/batch, start/pause/complete, live material and parameter status.
- **Material traceability / genealogy** — forward and backward, lot-to-lot and serial-to-serial,
  built on top of Inventory's own lot/serial identity rather than a second copy of it.
- **Quality gates embedded in execution**, not a bolted-on separate app — incoming, in-process,
  and finished-goods inspection can hold material/output from progressing.
- **MES does not own stock or employee identity** — Inventory remains system of record for stock
  (MES calls `InventoryService`), HCM remains system of record for people (MES references
  `HCM.employees`/`HCM.shifts` read-only), same "don't duplicate an existing Core module"
  discipline already applied across Sales/Purchase/Payroll.

---

# 2. Goals

> Designated features solving the Backgrounds above, phased for a solo developer
> (`CLAUDE.md` §10 MVP bias — same phasing style as `INVENTORY_SPECS.md` §2).

## Phase 1 — Core (ship first)
- Production Order (§3A) covering both `assembly` and `process` models.
- Routing/Operations (discrete) and Phases/Process-Parameters (process) master data (§3E, §3G) —
  material composition (BOM/Recipe) is owned by **PP**, not MES; see the boundary note in §3B.
- Work Center / Machine / Station master data (§3D).
- Shop Floor execution: Operation execution (assembly) and Batch/Phase execution (process)
  (§3H, §3I).
- Production event ledger (§3C) — every execution action recorded.
- Material consumption (issue/return, calls `InventoryService`) and Production output
  (finished/co-product/by-product/waste, calls `InventoryService`) (§3J).
- Scrap & rework (§3N).
- Lot/serial traceability & genealogy, built on Inventory's `stock_batches`/`stock_serials`
  (§3K).
- Basic Quality: inspection at in-process and finished-goods checkpoints, pass/fail, hold/release
  (§3L).

## Phase 2 — Operational (fast-follow)
- Shift reference (reuses `HCM.shifts`/`HCM.shift_assignments`, no new shift model) and shift
  handover notes (§3P).
- MES scheduling / sequencing (machine, material, operator, changeover, campaign production)
  (§3Q).
- Equipment status & downtime tracking (planned/unplanned, reason-coded) (§3M).
- OEE (Availability × Performance × Quality) for assembly lines; process-specific KPIs (yield,
  parameter-in-spec %) for continuous lines (§3O).
- Electronic Work Instructions attached per Operation/Phase, via DMS (§3E/§3G).
- Batch genealogy detail views, quality hold/release workflow via WNE (§3K, §3L).

## Phase 3 — Advanced (future version — do not build now)
- **§3R (Alerts & Andon) and §3S (IoT / PLC Integration) were built ahead of this phase gate**,
  by explicit user override (2026-09-02) — see each section's own build for what actually
  shipped: §3R ships the Andon read model + the six-condition alert sweep via WNE; §3S ships one
  concrete protocol adapter (REST/webhook, Sanctum bearer-token auth) behind the pluggable
  `IotProtocolAdapter` interface, plus the queued ingestion job — not MQTT/OPC-UA/Modbus, which
  still wait on real hardware/client libraries and stay properly Phase 3.
- Real-time process-parameter streaming and alarms — the alert *sweep* (§3R) now exists;
  sub-second streaming/alarming does not.
- Advanced finite-capacity scheduling / optimization.
- Predictive-maintenance integration (beyond the reactive downtime → maintenance-request hook in
  §3M).
- Advanced analytics (AIInsight composition, same ZDR gate as every other AI feature —
  `CLAUDE.md` §5).
- Electronic signatures on audit-trail entries (regulated-industry tier).

---

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB
> design.

## 3A. Production Order (`prod_order_hdrs`)

**Function / Features**
- Single order header type for both models; `production_model` (`assembly` | `process`)
  determines which child engine (§3H vs §3I) executes it and which master-data reference is
  required: `bom_id` + `routing_id` (discrete) or `recipe_id` (process). `bom_id`/`recipe_id` are
  cross-schema references into **PP**'s `pp_boms`/`pp_recipes` — material composition is PP's own
  master data, not MES's (see the boundary note in §3B below); `routing_id` (discrete operations)
  and the process-phase rows keyed by the same `recipe_id` (§3F) remain MES's own.
- Fields: product, `bom_id`/`routing_id` or `recipe_id` reference, quantity, UoM, production model, planned
  start/end, actual start/end, priority, warehouse, production line/area, status, parent order
  (for sub-assemblies/intermediate batches), source reference (`subject_type`/`subject_id` —
  polymorphic link back to the Sales order or MRP suggestion that generated it, same pattern as
  Inventory's `stock_reservations.subject_type`).
- Order number generated via `SYSCONFIG.config_snums` (`snum_code = MES_MO_LASTID`) — a simple
  tenant-wide running number, the customization-ladder rung-2 fit per `SYSCONFIG_SPECS.md` §3D
  (unlike Projects' per-parent issue counter, which stays local — `PROJECTS_SPECS.md` §4 note).
- Status lifecycle: `draft` → `released` → `in_progress` → (`paused`) → `completed` /
  `cancelled`. `Released` is the event that allows material reservation/issue to begin.

**Rules / Logic**
- `production_model` is immutable after creation (changing execution model mid-order makes no
  sense — cancel and re-create instead).
- Releasing an order fires `MES.prod_events` (`order_released`) and, if Inventory is enabled,
  creates reservations via `InventoryService::checkAvailability()`/reserve, same optional-module
  posture Purchase already uses for Goods Receipt (`PURCHASE_SPECS.md` §3E/§5).

## 3B. BOM / Recipe — Owned by PP (boundary note)

**Function / Features**
- Discrete BOM (`pp_boms`/`pp_bom_lines`) and Process Recipe/Formula (`pp_recipes`/
  `pp_recipe_ingredients`), including formula scaling (`RecipeService::scale()`), are owned by
  **PP** — `PP_SPECS.md` §3D — not MES. Material composition is planning master data the MRP
  engine explodes directly; MES only *consumes* the resolved product/quantity list at order and
  batch creation time, it does not store or edit it.
- MES reads active BOM/Recipe via `PpService::getActiveBom(productId)` /
  `PpService::getActiveRecipe(productId)`, and scaled batch quantities via
  `PpService::scaleRecipe(recipeId, targetBatchSize)` (wrapping PP's own `RecipeService::scale()`)
  — the mirror image of PP calling `MesService` for equipment/routing (§3E/§3F).
- What stays in MES: Routing/Operations (§3E, discrete — the *sequence of execution steps*, not
  material composition) and Process Phases/Parameters (§3F, process — same role for continuous
  manufacturing), both keyed by `product_id`/`recipe_id` rather than duplicating what PP already
  owns.

**Rules / Logic**
- A Production Order's `bom_id`/`recipe_id` (§3A) is resolved once at creation/release time and
  stored on the order — same "resolved value survives a later master-data edit" discipline this
  section held when MES owned the tables, now enforced by PP's own versioning
  (`PP_SPECS.md` §3D: only one active version per product; open orders keep the version they were
  released against).

## 3C. Production Event Ledger (`mes_prod_events`)

**Function / Features**
- Append-only log of every execution-significant action: `order_released`, `material_issued`,
  `material_returned`, `operation_started`, `operation_paused`, `operation_completed`,
  `machine_started`, `machine_stopped`, `parameter_recorded`, `qc_sample_taken`,
  `scrap_recorded`, `output_produced`, `downtime_started`, `downtime_ended`, `batch_split`,
  `batch_merged`.
- One row per event: order/batch/operation reference, event type, `payload` (jsonb — event-
  specific detail), `occurred_at`, `user_id`, `machine_id` (nullable).

**Rules / Logic**
- Every write path in §3H/§3I/§3J/§3L/§3M writes here — this table is the single source other
  engines (OEE §3O, Traceability §3K, Dashboards §3T) derive from; nothing else recomputes
  history independently.
- Immutable: corrections are new events (e.g. a corrective `scrap_recorded` with a negative
  delta and a reason), never `UPDATE`/`DELETE` on a past row — same append-only discipline as
  `INVENTORY.stock_ledger`.

## 3D. Equipment Master Data (`mes_work_centers` / `mes_machines` / `mes_stations`)

**Function / Features**
- Hierarchy: Plant (tenant) → Area/Line → Work Center → Machine → Station, matching the user
  architecture in §9 of this spec's source brief.
- `mes_work_centers`: code, name, area/line, type.
- `mes_machines`: work_center_id, code, name, current status (`running` / `idle` / `down` /
  `maintenance` / `setup` / `waiting_material` / `waiting_operator` / `waiting_qc`).
- `mes_stations`: work_center_id or machine_id, code, name — the physical spot an operator
  executes at (§3H's Shop Floor UI target).

## 3E. Routing / Operations (assembly)

**Function / Features**
- `mes_routings` (product, version, `is_active`) / `mes_routing_ops` (routing_id, sequence,
  operation code/name, work_center_id, setup_time, run_time, queue_time, standard output qty,
  scrap rule reference, instructions text, tool/document reference).
- Electronic Work Instructions (Phase 2): attaches via `DocumentService::attach()` with
  `subject_type = 'mes.routing_ops'`, same DMS integration pattern every other module uses
  (`DMS_SPECS.md`) — text/image/PDF/video/diagram, no separate file-storage table in MES.

## 3F. Process Phases & Parameters (process)

**Function / Features**
- `mes_process_phases` (`recipe_id` — cross-schema reference into **PP**'s `pp_recipes.id`, §3B —
  sequence, phase name, equipment type/work_center_id, standard duration). This is Routing's
  (§3E) counterpart for continuous manufacturing: the *execution-step* sequence, not the
  ingredient list, which is why it stays in MES even though the recipe header it hangs off now
  lives in PP.
- `mes_process_parameters` (`process_phase_id`, parameter code, target, min, max, UoM) — the
  spec/limit definition; actual readings live in `mes_batch_parameter_readings` (§3I), same
  target-vs-actual split as `mes_routing_ops` (planned) vs `mes_prod_events` (actual).

## 3G. Assembly Execution — Shop Floor Operation UI (`Show.vue`, Shop-Floor layout)

**Layout** (per the user's mockup in this spec's source brief)
```text
WO-00125
Station: Assembly-03
Product: XYZ
Target : 100
Done   : 74
Reject : 2
[ START ]  [ PAUSE ]  [ COMPLETE ]  [ SCRAP ]
Materials: ✓ Component A  ✓ Component B  ⚠ Component C — Low Stock
```
**Rules / Logic**
- Dedicated shop-floor UI, not the standard `AppLayout` admin chrome — large touch targets,
  minimal navigation, single order/operation focus.
- `START` writes `operation_started`; `PAUSE`/`COMPLETE` write matching events; `COMPLETE`
  increments produced qty and, if the operation is configured to auto-issue components 1:1 with
  standard BOM usage, calls Material Consumption (§3J).
- `SCRAP` opens the Scrap & Rework flow (§3N) scoped to the current operation.
- Component availability strip resolves the order's `bom_id` via `PpService::getActiveBom()`
  (§3B), then reads `InventoryService::checkAvailability()` per component line — read-only
  warning, does not block starting the operation (material shortage is an andon condition, §3R,
  not a hard stop in v1).
- Sequence enforcement: an operation cannot start until its routing-defined predecessor is
  `completed`, unless the routing marks it parallel-eligible.

## 3H. Assembly Execution — Serial Genealogy

**Function / Features**
- `mes_serial_links` (serial reference — Inventory's `stock_serials.id` — component_serial_id or
  component_lot_id, material_id, order_id, operation_id) records which components went into
  which finished serial, as each is consumed/completed.
- MES does **not** own the serial number itself; `stock_serials` (Inventory, `tracking_mode =
  serial`) is the identity, MES only records the parent→component linkage at the moment of
  consumption — same "don't duplicate the ledger" boundary as material consumption (§3J).

## 3I. Process Execution — Batch / Phase UI

**Layout**
```text
BATCH B-0031              RUNNING
Mixing
Temperature   79.8 °C   ✓
Pressure       5.1 bar  ✓
RPM            1,198    ✓
Elapsed        32:14
[ PAUSE ]   [ COMPLETE PHASE ]
```
**Storage / Rules**
- `mes_batches` (order_id, batch number, `recipe_id` — cross-schema reference into `PP.pp_recipes`
  — resolved scaled quantities via `PpService::scaleRecipe()` per §3B, status, planned qty, actual
  yield %).
- `mes_batch_phases` (batch_id, process_phase_id, sequence, status, start/end, operator_id,
  equipment/machine_id).
- `mes_batch_parameter_readings` (batch_phase_id, process_parameter_id, value, recorded_at,
  recorded_by, machine_id nullable — for future IoT-sourced readings, §3S) — the actual-value
  counterpart to `mes_process_parameters`'s target/min/max. A reading outside `[min, max]` writes
  a `parameter_recorded` event flagged `out_of_range` and, if the parameter is marked
  quality-critical, raises a QC hold (§3L).
- `PAUSE`/`COMPLETE PHASE` write matching `mes_prod_events` rows; completing the final phase
  completes the batch and triggers Production Output (§3J).
- Batch split/merge (e.g. one recipe run split into two downstream batches, or two partial runs
  combined): `mes_batch_relations` (parent_batch_id, child_batch_id, relation type `split` |
  `merge`, qty) — feeds Traceability (§3K).

## 3J. Material Consumption & Production Output (common)

**Function / Features**
- `mes_material_consumptions` (order_id, operation_id or batch_phase_id, material product,
  lot/serial reference — Inventory's own IDs, qty, UoM, type `issue` | `return`). Writing a row
  here calls `InventoryService::issue()`/`return` (mirrors Purchase → `InventoryService::
  receive()`, `PURCHASE_SPECS.md` §3E) — MES never writes to `INVENTORY.stock_ledger` directly.
- `mes_production_outputs` (order_id, operation_id or batch_phase_id, output type `finished` |
  `co_product` | `by_product` | `waste`, product, qty, UoM, lot/serial reference). Writing a row
  here calls `InventoryService::receive()` to post the finished/co-product goods into stock.
- Both consumption and output rows are the raw material for Traceability/Genealogy (§3K) — no
  separate genealogy-only storage duplicates this data.

**Rules / Logic**
- If the tenant's product is `tracking_mode = batch`/`serial` (Inventory setting), Output rows
  must carry the corresponding lot/serial; MES calls `InventoryService` to mint the new
  lot/serial at receipt time (Inventory owns the identity, per Backgrounds).
- Waste/by-product output does not require a sellable product master — a lightweight "waste"
  product category is sufficient, no MES-specific product concept.

## 3K. Traceability & Genealogy (View / Report)

**Function / Features**
- Forward trace: given a raw material lot, list every production output (and downstream
  shipment, via Inventory's `shipments`) it fed into — a recursive walk over
  `mes_material_consumptions` → `mes_production_outputs` → `mes_serial_links`/
  `mes_batch_relations`.
- Backward trace / recall: given a finished lot/serial, list every raw material lot and
  intermediate batch consumed to produce it.
- No dedicated genealogy table — this is a derived view over §3H/§3I/§3J's own transaction
  tables, consistent with "MES doesn't own stock, only the linkage" from Backgrounds. A
  materialized/cached genealogy table is an Advanced-phase option only if the recursive query
  becomes a real performance problem at scale.

## 3L. Quality Control (Phase 1 basic, Phase 2 hold/release workflow)

**Function / Features**
- `mes_qc_inspection_plans` (product or operation/phase reference, name) /
  `mes_qc_characteristics` (plan_id, characteristic name, spec type numeric/pass-fail, target/
  min/max, UoM).
- `mes_qc_samples` (order_id, operation_id or batch_phase_id, sample number, taken_by, taken_at)
  / `mes_qc_results` (sample_id, characteristic_id, actual value or pass/fail, result
  pass/fail/hold).
- `mes_qc_holds` (subject_type/subject_id — polymorphic: order, batch, output lot/serial,
  reason, status `open`/`released`, released_by, released_at).
- Checkpoints: incoming (before material_consumption is allowed to draw from a held lot —
  delegates to Inventory's reserved `quality_status` column on `stock_batches`/`stock_ledger`,
  `INVENTORY_SPECS.md` §3S), in-process (sample during an operation/phase), finished-goods
  (before Production Output is allowed to post as `available`, not just `on_hand`).

**Rules / Logic**
- A `fail` result on a finished-goods checkpoint auto-creates a `mes_qc_holds` row against the
  output lot/serial and blocks it from `InventoryService::receive()`'s availability flag (posts
  to stock as on-hand-but-held, not sellable) rather than blocking the physical receipt itself.
- Hold release (Phase 2) can optionally route through **WNE** (`WorkflowRequested`,
  `workflow_code = mes.qc_hold_release`) for tenants that require dual sign-off — same
  optional-approval pattern Sales uses for its quote approval (`SALES_SPECS.md`).
- NCR / CAPA are out of scope for Phase 1 — a `fail` result plus a hold plus a free-text reason
  is sufficient MVP; a dedicated NCR/CAPA workflow is an Advanced-phase addition once a
  regulated-industry tenant needs it.

## 3M. Equipment Status & Downtime (Phase 2)

**Function / Features**
- `mes_equipment_status_logs` (machine_id, status, started_at, ended_at) — append-only status
  history, current status denormalized onto `mes_machines.status` for fast dashboard reads
  (rebuildable from the log, same `stock_balances`-from-`stock_ledger` cache pattern Inventory
  uses).
- `mes_downtime_events` (machine_id or work_center_id, order_id nullable, category `planned` |
  `unplanned`, reason_code — `maintenance`/`setup` for planned, `mechanical`/`electrical`/
  `material_shortage`/`quality`/`operator` for unplanned, started_at, ended_at).
- Unplanned downtime past a configurable duration threshold (`SYSCONFIG.config_consts`,
  customization-ladder rung 1) auto-creates a maintenance request — MES owns the *operational
  event*, a future dedicated Maintenance module (not yet built, out of scope here) would own the
  work order; until that exists, the request is a simple WNE notification to the maintenance
  contact on file, not a stored work order.

## 3N. Scrap & Rework (common)

**Function / Features**
- Scrap is a `mes_production_outputs` row with `output_type = waste` plus a `reason_code` and
  `disposition` (`scrap` | `rework`).
- Rework: `disposition = rework` routes the quantity to a rework operation/phase (reuses §3G/
  §3I's execution engine against a rework-flagged routing/recipe step) → re-inspected via §3L →
  `pass` posts as `finished`, `fail` posts as `scrap`.
- Yield is computed, not stored: `good_output_qty / (good_output_qty + scrap_qty)` per order/
  batch, read from `mes_production_outputs`.

## 3O. OEE & Process KPIs (Phase 2, View / Report)

**Function / Features**
- Assembly: `OEE = Availability × Performance × Quality`, each computed from `mes_prod_events`
  (Availability: planned time minus `mes_downtime_events`), `mes_routing_ops` standard vs. actual
  cycle time (Performance), and §3L results (Quality). Drill-down Line → Station → Machine →
  Day, per the user's mockup.
- Process: yield % (§3N), parameter-in-spec % (share of `mes_batch_parameter_readings` within
  `[min, max]`), QC hold count — process-specific KPIs rather than forcing assembly-style OEE
  onto continuous lines, per Backgrounds.
- All figures are computed read models over §3C/§3J/§3L/§3M — no separate KPI storage table;
  cache/materialize only if a specific dashboard query proves too slow at real tenant scale.

## 3P. Shift Reference & Handover (Phase 2)

**Function / Features**
- No MES-owned shift model — reads `HCM.shifts`/`HCM.shift_assignments` directly (read-only),
  per Backgrounds ("keep employee master data in HR and let MES reference it").
- `mes_shift_handover_notes` (shift_assignment_id — HCM reference, order/batch summary at
  handover time, free-text notes e.g. machine issue / last QC result) is the one MES-owned
  table here, since shift handover content is production-specific, not an HCM concern.

## 3Q. MES Scheduling (Phase 2)

**Function / Features**
- Short-term shop-floor sequencing, consuming the ERP-level production plan (future MRP, out of
  scope here) rather than replacing it, per the Planning architecture in this spec's source
  brief (`Sales → MRP → Production Plan → MES Schedule → Shop Floor`).
- Considers machine capacity (`mes_machines`), material availability
  (`InventoryService::checkAvailability()`), operator availability (`HCM.shift_assignments`),
  setup/changeover time (`mes_routing_ops.setup_time` / recipe phase equivalent), priority, due
  date.
- Campaign scheduling for process manufacturing: grouping same-recipe orders consecutively to
  minimize changeovers — a sequencing rule over `mes_prod_order_hdrs`, not a new storage
  concept.
- Composes with the existing **Schedule** Core module's Resource/Availability engine
  (`SCHEDULE_SPECS.md` §3D/3E) for the operator/machine calendar rather than building a second
  calendar engine — same "don't duplicate an existing Core module" rule Inventory's Dock
  Scheduling (Advanced) already follows (`INVENTORY_SPECS.md` §3S).

## 3R. Alerts & Andon (Phase 3 — built ahead of schedule, see §2 note)

**Function / Features**
- Andon states (`running` / `attention` / `stopped` / `maintenance`) derived from
  `mes_machines.status` + open `mes_downtime_events` + open `mes_qc_holds` + material-shortage
  warnings — a read model, not new storage.
- Alert delivery (material shortage, machine stopped, out-of-spec parameter, behind schedule,
  overdue batch, maintenance required) routes through **WNE**'s existing notification engine
  (`NotificationRequested`), same integration seam every other module uses — MES does not build
  its own notification channel.

## 3S. IoT / PLC Integration (Phase 3 — built ahead of schedule, see §2 note)

**Function / Features**
- Integration layer only, never hard-coded machine protocol handling inside MES's own services:
  `PLC/SCADA → IoT Gateway → MQTT/OPC-UA → MES Integration Layer → mes_prod_events /
  mes_batch_parameter_readings`.
- Ingestion is a queued job (Redis, per `CLAUDE.md` §3) writing into the same §3C/§3I tables
  operator-entered data uses — the execution engine has one write path regardless of whether a
  human or a machine produced the event.
- Protocol adapters (OPC-UA/MQTT/Modbus/REST/WebSocket) are a pluggable interface; if a specific
  protocol's throughput/runtime needs outgrow the monolith (e.g. sustained high-frequency
  time-series ingestion), that adapter — not all of MES — is the microservice-extraction
  candidate, evaluated against `CLAUDE.md` §2's criteria at that time, not preemptively.

## 3T. Dashboards

**Function / Features**
- Plant Dashboard: production-to-plan %, OEE, downtime, reject rate, active orders, active
  batches.
- Line Dashboard: per-line running state, OEE, target vs. actual, reject count, downtime.
- Process Area Dashboard: active batches, average yield, parameter alarms, QC holds.
- Same "several focused dashboards, not one giant one" posture as the user's source brief —
  each is a read model over §3C/§3J/§3L/§3M/§3O, composed from `StatCard`/`Panel`
  (`CLAUDE.md` §9D5) per the shared design system.

## 3U. Digital Audit Trail (`mes_audit_logs`)

**Function / Features**
- Field-level change log — who/what/when/where/before/after/reason — for governance-sensitive
  edits (e.g. a process-parameter target changed on an active recipe, a QC result overridden, a
  hold manually released). Distinct from `mes_prod_events` (§3C): events are the *business*
  action stream (start/stop/consume/produce); `mes_audit_logs` is the *change-history* stream
  for edits to already-recorded data, same split `SYSCONFIG` documents between
  `config_audit_logs` and its own runtime config (`SYSCONFIG_SPECS.md` §3G).
- Same append-only, per-module `*_audit_logs` convention as `SYSCONFIG.config_audit_logs`,
  `WNE.wrkflow_audit_logs`, `ACCOUNTING.audit_logs`, `DMS.access_logs`.

---

# 4. Storage

> Tables and schema layout under tenant `MES` PostgreSQL schema.

**Master / lookup tables**
- `MES.mes_work_centers`, `MES.mes_machines`, `MES.mes_stations` (§3D)
- `MES.mes_routings`, `MES.mes_routing_ops` (§3E, discrete)
- `MES.mes_process_phases`, `MES.mes_process_parameters` (§3F, process) — `recipe_id` is a
  cross-schema reference into `PP.pp_recipes` (§3B boundary note; BOM/Recipe itself lives in
  `PP.pp_boms`/`PP.pp_recipes`, not here)
- `MES.mes_qc_inspection_plans`, `MES.mes_qc_characteristics` (§3L)

**Transaction / execution tables**
- `MES.mes_prod_order_hdrs` (§3A) — references `INVENTORY.products`, `INVENTORY.warehouses`, and
  `PP.pp_boms`/`PP.pp_recipes` (`bom_id`/`recipe_id`, cross-schema — §3B)
- `MES.mes_prod_events` — append-only (§3C)
- `MES.mes_batches` (`recipe_id` cross-schema into `PP.pp_recipes`), `MES.mes_batch_ingredients`
  (per-ingredient resolved scaled quantities — the concrete storage behind §3I's "batch stores
  the resolved scaled quantities" line), `MES.mes_batch_phases`,
  `MES.mes_batch_parameter_readings`, `MES.mes_batch_relations` (§3I, process)
- `MES.mes_serial_links` (§3H, assembly) — references `INVENTORY.stock_serials`
- `MES.mes_material_consumptions`, `MES.mes_production_outputs` (§3J) — reference
  `INVENTORY.stock_batches`/`INVENTORY.stock_serials`
- `MES.mes_qc_samples`, `MES.mes_qc_results`, `MES.mes_qc_holds` (§3L)
- `MES.mes_equipment_status_logs`, `MES.mes_downtime_events` (§3M, Phase 2)
- `MES.mes_shift_handover_notes` (§3P, Phase 2) — references `HCM.shift_assignments`
- `MES.mes_audit_logs` — append-only (§3U)

**Custom fields:** `mes_prod_order_hdrs` and `mes_batches` are registered as extensible entities
in `CUSTOMFIELDS.field_defs`, same pattern as every other Core module's master/transaction
headers (BOM/Recipe's own custom-field registration is PP's, `PP_SPECS.md` §4).

**Object File** (per `CLAUDE.md` §7B):
- MES owns no top-level R2 folder of its own — Electronic Work Instructions, QC certificates,
  and any batch/order attachments route entirely through **DMS**'s structure
  (`DMS/MES/...` subfolder) with a `subject_type`/`subject_id` pointer back to the owning
  record, same as WNE/HCM/Sales already do (`CLAUDE.md` §7B).

---

# 5. Technical Notes

- **One engine, two models**: `production_model` on `mes_prod_order_hdrs` is the single branch
  point. Discrete execution walks Operations (§3E/§3G); process execution walks Phases (§3F/
  §3I). Every other engine (events, material, output, quality, traceability, equipment, OEE,
  dashboards, audit) is model-agnostic and reads/writes the same shared tables — this is the
  core architectural decision this module is built around, per the source brief's §25.
- **MES never owns stock or identity it doesn't have to**: material consumption/output always
  goes through `InventoryService`; lot/serial identity is Inventory's; shift/employee identity is
  HCM's; material composition (BOM/Recipe) is **PP's** (§3B boundary note). MES owns *production
  execution facts* (what happened, when, by whom, against which order/batch) plus the
  *execution-step* master data (Routing/Process-Phases, §3E/§3F) that consumes PP's composition
  data — the same boundary discipline `CLAUDE.md` §2 requires between Core modules.
- **Frontend**: dedicated Shop Floor layout (§3G/§3I) separate from `AppLayout` admin chrome —
  still composed from the shared design system (`DataTable`, `Panel`, `StatCard`, `StatusBadge`,
  `CLAUDE.md` §9D) for every non-shop-floor screen (order entry, BOM/recipe admin, dashboards,
  QC admin).
- **Append-only tables**: `mes_prod_events` and `mes_audit_logs` are never updated/deleted in
  place, matching `INVENTORY.stock_ledger`'s and `SYSCONFIG.config_audit_logs`'s existing
  discipline.
- **Async by default for machine-sourced data**: IoT/PLC ingestion (§3S) and any future
  high-frequency parameter streaming go through Redis queues, never a synchronous request path,
  so a flaky gateway can't block operator-facing execution.
- **Menu/permission codes**: `menu.perm:MES_*` (e.g. `MES_PROD_ORDER`, `MES_QC`,
  `MES_SHOPFLOOR`) via SYSCONFIG trustee middleware, same as every other module (`CLAUDE.md`
  §4).
- **Plan gating**: `module:MES` middleware + `config/tenant_modules.php` entry — added to `full`
  only; whether MES belongs on a narrower, dedicated Manufacturing plan instead is tracked as an
  open item below rather than guessed at here.

---

# 6. Build Order

> Recommended sequence for implementing this module's own pieces, and why. This is internal to
> MES — see `CLAUDE.md` §5 for where the whole module sits in the platform-wide build order
> (sequenced after Performance, before AIInsight — depends on Inventory/HCM/WNE/DMS, all earlier
> in that list).

1. **Master data (§3D, §3E/§3F)** — Work Centers/Machines/Stations first (no dependents), then
   Routing (discrete) and Process Phases/Parameters (process). Routing depends only on
   `INVENTORY.products`; Process Phases additionally depends on **PP**'s `pp_recipes` already
   existing (§3B boundary note) for its `recipe_id` FK — BOM/Recipe master data itself is no
   longer built here, see §3B.
2. **Production Order (§3A)** — depends on master data above; nothing else in MES works without
   an order to attach to.
3. **Production Event Ledger (§3C)** — build alongside Production Order, since Order Released is
   the first event; every later engine writes here, so it must exist before §3G/§3I.
4. **Material Consumption & Output (§3J)** — needs `InventoryService` integration; build before
   §3G/§3I so the execution UIs have something to call on Complete.
5. **Assembly execution (§3G, §3H)** or **Process execution (§3F storage, §3I)** — build
   whichever production model the first real tenant actually needs; the other can lag, since
   nothing else in Phase 1 depends on both existing simultaneously.
6. **Scrap & Rework (§3N)** — thin layer over §3J's output table, ships alongside whichever
   execution UI (§5) lands first.
7. **Traceability & Genealogy (§3K)** — pure read model over §3H/§3I/§3J; ships once both
   consumption and output have real data to query.
8. **Basic Quality (§3L)** — checkpoints hang off §3G/§3I completion events and §3J output
   creation; needs both to exist first.
9. **Digital Audit Trail (§3U)** — thin cross-cutting concern, wire in incrementally as each
   above engine's governance-sensitive edit paths are identified, rather than building it
   monolithically up front.
10. Phase 2 items (§3M, §3O, §3P, §3Q) and Phase 3 items (§3R, §3S) follow only after Phase 1 is
    validated with a real tenant, per §2's phasing — **§3R/§3S were the exception, built ahead
    of a real tenant per explicit override, see §2's note**; §3T (untagged, no phase gate) was
    built in the same pass.

---

# 7. Open Items

- [x] **Platform build order placement** — `CLAUDE.md` §5 lists MES, sequenced after
      Performance, before AIInsight.
- [x] **`config/tenant_modules.php` entry** — added to `full`.
- [ ] **Dedicated Manufacturing plan/vertical** — `full` is a placeholder bundle ("everything");
      whether MES eventually needs its own plan tier (so a manufacturing tenant isn't paying for
      Legal-only modules, or vice versa) is a decision for when a real manufacturing tenant is a
      prospect, not guessed at here.
- [ ] **Maintenance module** — §3M's downtime → maintenance-request hook is a WNE notification
      only until a dedicated Maintenance module (preventive/corrective work orders, spare parts,
      assets) exists; out of scope for this spec.
- [x] **MRP / Production Planning** — resolved: **PP** (`app/Modules/PP/PP_SPECS.md`) is the
      Demand/MPS/MRP/Capacity/Scheduling engine §3Q assumed would exist; it owns BOM/Recipe
      master data (§3B boundary note) and releases planned orders into `mes_prod_order_hdrs`
      (§3A).
- [ ] **`PpService` contract implementation** — §3B/§3F now call `PpService::getActiveBom`/
      `getActiveRecipe`/`scaleRecipe`; named here and in `PP_SPECS.md` §3D but not yet
      implemented — tracked once, in `PP_SPECS.md` §7, not duplicated here.
- [x] **Build-order consequence of the BOM/Recipe move** — because Process Phases (§3F) now FK
      into `PP.pp_recipes`, PP's BOM/Recipe master data must exist before MES's Process Phases
      can be built. `CLAUDE.md` §5 now sequences **PP before MES**, reversing the placement
      recorded when PP was first added (see `PP_SPECS.md` §7 for the corresponding update).
