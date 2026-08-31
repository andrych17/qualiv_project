# PP Module
## Core Shared Module — Production Planning & Scheduling (Hybrid Discrete/Process, Multi-Level Planning Engine)

**Module category** (`CLAUDE.md` §2/§10): **Core**. PP has zero knowledge of any vertical — it is
a shared planning engine any manufacturing tenant rents regardless of what they make, same
posture as **MES** (`MES_SPECS.md`). It is not Platform-level (lives in the tenant DB like every
other Core module) and no `CLAUDE.md` §2 microservice-extraction criterion is met today (no
distinct scaling need, no non-PHP runtime requirement, no cross-product reuse outside this
monolith, no elevated-isolation data) — see §7 Open Items for the one piece (a future constraint
solver) that could revisit this later, not preemptively. It is now listed in `CLAUDE.md`
§4/§5/§7A (schema `PP`, sequenced immediately **before MES** in the Core build order, since MES's
Process Phases master data FKs into this module's own `pp_recipes` — see §7) and unlocked on the
`full` plan in `config/tenant_modules.php`; not yet on `starter`, `legal`, or `internal`, same
placeholder posture MES itself started from.

**Relationship to MES**: PP is the *Planning* half of the split `MES_SPECS.md` §22 already draws
between "what should we produce, when, using what resources" (Planning) and "what is actually
happening on the shop floor" (MES/execution). `MES_SPECS.md` §7 Open Items lists "MRP /
Production Planning… not specced anywhere yet" as a known gap feeding its §3Q scheduling — this
spec is that gap closed. PP owns material composition (BOM/Recipe, §3D) as planning master data —
material composition is what MRP explodes, so it belongs here, not in execution. PP does not
re-litigate what MES already owns and executes (Work Center/Machine/Station identity,
Routing/Process-Phase execution steps, the Production Order record, the event ledger, execution
UI) — it calls into MES's service contracts for those and hands off a release; MES in turn calls
into PP's own service contracts for BOM/Recipe, the same two-way "don't duplicate another Core
module's table" discipline MES itself applies to Inventory and HCM (`MES_SPECS.md` §5).

---

# 1. Backgrounds

> Pain point and business value.

- MRP-only tools assume one production model. This platform must plan **both** discrete
  (BOM → components → operations) and process (formula → batch → yield/co-products) manufacturing
  with one engine, not two, or every planning feature (netting, capacity, scheduling, exceptions)
  gets built twice.
- MES today has no upstream source of *planned* work: `mes_prod_order_hdrs` (`MES_SPECS.md` §3A)
  can only be created manually or from a bare Sales order reference. There is no layer that turns
  demand into a feasible, capacity-checked, material-checked production plan before it becomes an
  order MES executes — so planners either overload machines/tanks without knowing it, or discover
  material shortages only after release.
- Treating "Demand → MRP → Production Order" as a single-pass calculation (classic MRP) ignores
  capacity entirely; a plan that is materially feasible but impossible to execute on the floor
  (machine overloaded 124%, tank unavailable) is discovered too late, the same failure mode
  `MES_SPECS.md` Backgrounds describes for execution visibility, one layer up.
- Planning and execution must stay decoupled (`MES_SPECS.md` §22): if PP reached into
  `MES.mes_prod_events` or Schedule reached into `MES.mes_machines` directly, a change to either
  module's internals would break the other. The boundary has to be service contracts, not shared
  tables.

**Client requirements** (from the source brief):
- **One planning engine, two production models** — discrete and process share the same Demand,
  MPS, MRP, Capacity, and Scheduling concepts; only BOM-explosion vs. formula-scaling differs, and
  PP owns both directly (§3D below), rather than reading a second module's master data for its
  own core calculation.
- **Five planning layers**, each with its own horizon and purpose, not one algorithm trying to
  solve everything: Demand Planning → MPS → Material Planning (MRP) → Capacity Planning →
  Detailed Scheduling → (release to) Production Orders → MES execution.
- **Capacity modeled generically** — machine-hours, labor-hours, material quantity, storage/tank
  capacity, and utility capacity (steam, compressed air, electricity) are all "resource load vs.
  resource availability" over a period, not five bespoke engines.
- **Resource groups** (e.g. "MIXING" = Mixer 01/02/03) so high-level capacity planning doesn't
  force the planner to pick a specific machine before it matters.
- **Exception-driven planning UX** — planners triage a short exception list, not thousands of
  individual orders.
- **Scenario / what-if planning** that never touches live inventory, orders, or the baseline plan.
- **Campaign/changeover-aware sequencing** for process manufacturing, and configurable dispatch
  rules for discrete — a strategy the scheduler applies, not a hard-coded algorithm.
- **PP does not own *execution* identity** — Work Center/Machine/Station and the Production
  Order record itself remain MES's; PP calls MES's service contracts for those and writes a
  release, the same boundary MES holds with Inventory/HCM (`MES_SPECS.md` §5). **PP does own**
  material composition (BOM/Recipe, §3D) as planning master data — the one place this spec
  differs from a naive "MES owns everything manufacturing" reading of the brief.

---

# 2. Goals

> Phased per `CLAUDE.md` §10 MVP bias, same phasing style as `MES_SPECS.md` §2. Priorities below
> are the source brief's own P0–P3 table, mapped onto MES's three-phase shape: **Phase 1 = P0,
> Phase 2 = P1, Phase 3 = P2 + P3 combined** (traceable 1:1, not re-derived).

## Phase 1 — Core (ship first, = brief's P0)
- Item Planning Parameters (§3A).
- Demand aggregation from Sales orders, forecast, safety stock/reorder points (§3B).
- BOM/Recipe master data — PP's own, not read from MES — plus MRP netting and explosion (§3D).
- Planned Orders (production / purchase / transfer) and release-to-MES handoff (§3D, §3K).
- Rough resource/capacity check reusing MES equipment identity and Schedule's calendar (§3E,
  §3F).
- Basic Planning Exception Center (§3M).

## Phase 2 — Operational (fast-follow, = brief's P1)
- Master Production Schedule grid with drill-down and freeze fence (§3C).
- Rough-Cut Capacity Planning (RCCP) board and capacity-by-dimension (machine/labor/material/
  storage/utility) (§3F, §3G).
- Resource Groups and alternate-resource support (§3E).
- Finite-capacity Detailed Scheduling / Gantt board (§3H).
- Scheduling rules (dispatch strategies) and Setup/Changeover matrix (§3I, §3J).
- Process-manufacturing planning specifics: campaign grouping, batch-size planning against PP's
  own recipe yield, tank capacity (§3K covers the constraint side generically).
- Full Exception Center with drill-down and suggested actions (§3M).

## Phase 3 — Advanced (future version, = brief's P2 + P3 — do not build now)
- Scenario / what-if planning, including "what-if rescheduling" for a specific order (§3L, §3N).
- Automatic sequence optimization (beyond configurable dispatch rules).
- Advanced constraint solver (material + resource + sequence + tank + quality + labor jointly,
  beyond the Phase 1 per-constraint checks in §3K).
- AI-assisted planning / schedule recommendations — AIInsight composition, same Zero Data
  Retention gate as every other AI feature (`CLAUDE.md` §5).

---

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB
> design.

## 3A. Item Planning Parameters (`pp_item_planning_params`)

**Function / Features**
- One row per `INVENTORY.products` item (not a copy of the product master — a planning-only
  companion row, same pattern as a custom-fields extension without using EAV for something this
  structured): make-to-stock/make-to-order, lot sizing (minimum/maximum/fixed/economic), safety
  stock, lead time, planning lead time, order multiple, scrap %, yield % override, production
  calendar reference, preferred/alternate production line (`MES.mes_work_centers` reference, via
  `MesService`, not a raw FK reach-through), planning fence.
- Registered in `CUSTOMFIELDS.field_defs` like every other Core module's master row, so tenants
  can extend it via the customization ladder (`CLAUDE.md` §2) instead of PP code branches.

## 3B. Demand Aggregation (`pp_demand_hdrs` / `pp_demand_lines`)

**Function / Features**
- Aggregates production requirements from: Sales orders (`SALES.so_hdrs`, via a
  `SalesOrderRequested`-style event PP subscribes to, same pattern `SALES_SPECS.md` §3I already
  uses for Accounting), sales forecast (`pp_demand_forecasts`, manually entered or imported),
  safety stock / reorder points (reads `pp_item_planning_params` + `InventoryService::
  checkAvailability()`), blanket orders / customer schedules (Sales-owned, referenced not
  copied), manual production plans (planner-entered rows), dependent demand (BOM/recipe
  explosion of a higher-level item's planned order — §3D), inter-warehouse transfer demand
  (`INVENTORY.stock_reservations`-style reference).
- Each demand line carries `scenario_id` (nullable — see §5 Scenario Isolation); `NULL` is the
  live/baseline plan every other engine in this module reads by default.

**Rules / Logic**
- Demand aggregation is additive and read-mostly: PP never edits a Sales order or an Inventory
  reorder point, it only reads them to compute a demand row — same "don't duplicate the source of
  truth" discipline as MES's material consumption (`MES_SPECS.md` §3J).

## 3C. Master Production Schedule — MPS (`pp_mps_hdrs` / `pp_mps_lines`)

**Layout**
```text
Item        W1     W2     W3     W4     W5
──────────────────────────────────────────
Product A  1,000  1,500  1,000  2,000  1,500
Product B    500    800    600    700    900
Product C  2,000  2,000  1,500  2,500  2,000
```
- Each cell drills down: **Demand → Planned Production → Material → Capacity → Orders** — a
  detail panel composed from §3B/§3D/§3F/§3D-release data for that item/period, not a separate
  stored drill-down table.

**Rules / Logic**
- Time-phased (period = tenant-configurable week/month via `SYSCONFIG.config_consts`, rung 1).
- Controls: freeze/unfreeze period (blocks MRP from replanning a frozen cell), split/consolidate
  production quantity across periods, change due date, change production line
  (`mes_work_centers` reference), firm a planned order (excludes it from automatic MRP
  regeneration), release a planned order (§3D's release action), exception warnings inline
  (reads §3M).
- `pp_mps_lines` also carries `scenario_id` — an MPS edited inside a scenario never writes to the
  baseline row.

## 3D. BOM / Recipe Master Data & MRP Engine (`pp_boms` / `pp_bom_lines` / `pp_recipes` /
`pp_recipe_ingredients` / `pp_mrp_runs` / `pp_planned_orders`)

**Function / Features — BOM / Recipe (owned here, not MES)**
- **Discrete BOM** (`pp_boms` / `pp_bom_lines`): header — product, version, effective dates,
  `is_active`; lines — component product, quantity per parent unit, UoM, scrap %.
- **Process Recipe / Formula** (`pp_recipes` / `pp_recipe_ingredients`): header — product,
  version, batch size, UoM, expected yield %, expected waste %; ingredients — raw material
  product, quantity per recipe batch size, UoM.
- **Formula scaling**: `RecipeService::scale(recipe, target_batch_size)` returns scaled
  ingredient quantities (`qty = recipe_qty * target_batch_size / recipe.batch_size`) — pure
  calculation, no stored scaled rows; MES's Batch (`MES_SPECS.md` §3I) stores the *resolved*
  scaled quantities at creation time (via `PpService::scaleRecipe()`) so historical batches stay
  accurate even if the recipe is edited later.
- Material composition is deliberately **planning** master data, not execution master data: it
  is what MRP explodes to compute net requirements, and it changes on a planning cadence
  (engineering change, cost rollup) independent of any specific shop-floor run. MES's own
  Routing/Process-Phases (`MES_SPECS.md` §3E/§3F) — the *execution-step* sequence — stay in MES,
  since they're tied to work-center/equipment identity MES owns. MES reads BOM/Recipe via
  `PpService::getActiveBom(productId)`/`getActiveRecipe(productId)`, the mirror image of PP
  calling `MesService::listResources()` for equipment (§3E).
- Only one `is_active = true` version per product at a time; creating a new active version does
  not retroactively change open orders or already-released MES production orders (they keep the
  `bom_id`/`recipe_id` they were released against).

**Function / Features — MRP Engine & Planned Orders**
- Net requirements calculation per item/period:
```text
Gross Requirement
- Available Inventory
- Scheduled Receipts
- Expected Production
+ Safety Stock
-------------------
Net Requirement
```
- BOM explosion (discrete) and Formula explosion (process) read directly from this section's own
  `pp_boms`/`pp_recipes` — no cross-module service call needed for PP's own data, unlike
  Resource/Capacity (§3E/§3F), which does reach into MES.
- Output: `pp_planned_orders` rows, one of three types — `production` (discrete or process),
  `purchase` (component/raw-material shortage), `transfer` (inter-warehouse). Each row: item,
  quantity, need-by date, source demand reference (`subject_type`/`subject_id` back to the
  `pp_demand_lines`/`pp_mps_lines` row that generated it), `scenario_id`.
- Order numbering: `SYSCONFIG.config_snums` (`snum_code = PP_PLAN_LASTID`) — PP's own series,
  distinct from MES's `MES_MO_LASTID` (`MES_SPECS.md` §3A); PP never assigns an MES order number.

**Rules / Logic — Release (the Planning → Execution seam)**
- Releasing a `production`-type planned order calls `MesService::createProductionOrder(bomId:
  ..., recipeId: ..., ...)`, which creates a `MES.mes_prod_order_hdrs` row with `bom_id`/
  `recipe_id` set to PP's own row (a cross-schema reference, per `MES_SPECS.md` §3A/§3B) and
  `subject_type = 'pp.planned_order'`, `subject_id = pp_planned_orders.id` — exactly the "MRP
  suggestion" source reference `MES_SPECS.md` §3A already anticipated for this field. MES owns
  everything from that point: its own order number, status lifecycle, and execution.
- Releasing a `purchase`-type planned order calls `PurchaseService` to create a purchase
  requisition (same `BillRequested`-style seam `PURCHASE_SPECS.md` §3F already defines for
  AP), not a PP-owned purchase-order table.
- Releasing a `transfer`-type planned order calls `InventoryService` to create a transfer
  request.
- A planned order inside a non-null `scenario_id` can never be released — release is a baseline-
  only action, enforced at the service layer, not just the UI (§5 Scenario Isolation).

## 3E. Resource & Resource Group Reference (`pp_resource_groups`, `pp_resources`)

**Function / Features**
- PP does **not** re-model machine/work-center identity — `MES.mes_work_centers`/
  `mes_machines`/`mes_stations` remain the identity (`MesService::listResources()`), and labor
  availability remains `HCM.shifts`/`HCM.shift_assignments` (read-only, same posture
  `MES_SPECS.md` §3P already holds). `pp_resources` exists only for resource *types* no other
  Core module owns yet — tool, tank, utility (steam/compressed air/electricity/gas/cooling
  water), warehouse-as-capacity — each row: type, code, name, capacity, UoM, `external_type`/
  `external_id` (nullable — set when the row is really an MES machine or HCM labor group being
  aliased into a resource group, not duplicated).
- `pp_resource_groups` (e.g. "MIXING") / `pp_resource_group_members` (group_id, resource
  reference — MES machine or `pp_resources` row) — lets the planner request "20 machine-hours of
  MIXING" without picking Mixer 01 vs. 02 vs. 03 at the planning stage (per source brief §7); the
  Detailed Scheduler (§3H) makes the specific assignment later.

**Rules / Logic**
- Calendar/shift/maintenance-window data is never re-entered in PP — every capacity calculation
  (§3F) calls Schedule's `AvailabilityService::isFree()`/`findConflicts()`
  (`SCHEDULE_SPECS.md` §3E) for the calendar, and MES's equipment status
  (`MES.mes_equipment_status_logs`, `MES_SPECS.md` §3M) for planned/unplanned downtime, the same
  "compose with Schedule, don't build a second calendar" rule `MES_SPECS.md` §3Q already commits
  to.

## 3F. Capacity Planning — RCCP (`pp_capacity_plans`)

**Layout**
```text
Work Center   Required   Available   Load
─────────────────────────────────────────
Cutting          420 hr      480 hr    88%
Welding          620 hr      500 hr   124%  ⚠
Painting         380 hr      420 hr    90%
Assembly         710 hr      800 hr    89%
```

**Function / Features**
- One `pp_capacity_plans` row per resource-or-resource-group / period / `scenario_id`: required
  load (summed from MPS/MRP output × routing or recipe-phase standard time, via `MesService`),
  available capacity (Schedule's `AvailabilityService` + resource calendar), computed load %.
- Rough-cut only in Phase 1 (infinite-capacity assumption, load vs. available is informational);
  finite enforcement is Detailed Scheduling's job (§3H), per §3G's finite/infinite split.

**Rules / Logic**
- Overload (load % > 100, or a tenant-configured threshold via `SYSCONFIG.config_consts`) writes
  a §3M exception with suggested actions surfaced from the source brief §8 list: add overtime,
  add shift, move production, use alternate resource, outsource, change quantity/due date.

## 3G. Capacity by Multiple Dimensions

**Function / Features**
- Not five separate engines — §3F's load/available calculation is parametrized by
  `pp_resources.type` (machine, labor, material, storage/tank, utility) and its UoM (hours, kg/
  L/units, or native utility unit). One capacity-check service, one dimension column, per source
  brief §9's own framing:
```text
Production Plan
       │
       ├── Machine capacity       OK
       ├── Labor capacity         OK
       ├── Raw material           OK
       ├── Tank capacity          OVER
       └── Steam capacity         OVER
```

## 3H. Detailed Scheduling (`pp_schedule_ops`)

**Layout**
```text
             Mon       Tue       Wed
             08 12 16 20 08 12 16 20
Machine 01   ████████      ███████
Machine 02       █████████████
Machine 03               █████████
```

**Function / Features**
- Finite-capacity, resource-and-time-specific proposal for each planned/released order's
  operations (discrete) or phases (process): specific machine, specific start/end, respecting
  Schedule's `AvailabilityService` conflicts and MES's setup/run-time standards
  (`mes_routing_ops` / recipe-phase duration, read via `MesService`).
- Finite vs. infinite capacity is a per-plan toggle (`SYSCONFIG.config_consts`, customization
  ladder rung 1): infinite for long-horizon MPS/MRP (§3C/§3D), finite for this near-term board.
- Operations are movable: drag/drop, change resource, change date, change sequence, split
  operation, merge batches (process) — writes to `pp_schedule_ops`, never to
  `MES.mes_prod_events` (that stays execution-only, per Backgrounds).

**Rules / Logic — the PP/MES scheduling boundary**
- **PP owns the schedule *proposal*** — the finite/infinite toggle, sequencing-rule application
  (§3I), changeover-matrix optimization (§3J), campaign grouping (§3K), and this Gantt board.
  **MES §3Q narrows to real-time dispatch-list ordering on the floor** — re-sequencing the queue
  in front of an operator as actual conditions change (a machine just went down, a rush order just
  landed) — consuming PP's proposal as its starting point rather than replacing it. This is the
  one point where the two specs would otherwise overlap; this paragraph is the resolution both
  specs defer to.
- Releasing a scheduled operation (assigning it a firm resource/time) is what actually creates or
  updates the corresponding `MES.mes_prod_order_hdrs` via §3D's release action — Detailed
  Scheduling is "when MRP's planned order becomes schedule-committed," not a separate release
  path.

## 3I. Scheduling Rules (Dispatch Strategies)

**Function / Features**
- A pluggable strategy service (`SchedulingRuleService::apply(strategy, operations[])`), not a
  hard-coded algorithm: FIFO, Earliest Due Date, Shortest/Longest Processing Time, priority
  (customer or sales-order priority, read from `SALES.so_hdrs`), plus manufacturing-specific
  strategies — minimize setup, group by product family/color/material, campaign production
  (process), minimize changeover, maximize utilization.
- Tenant selects a default strategy per resource group (customization ladder rung 1/4); the
  planner can override per scheduling run.

## 3J. Setup & Changeover Matrix (`pp_changeover_matrix`)

**Function / Features**
- `pp_changeover_matrix` (from_product_or_family, to_product_or_family, resource_type or
  resource_group_id, changeover_time, cleaning_time).
```text
From \ To      Product A   Product B   Product C
─────────────────────────────────────────────────
Product A         0 min       30 min      60 min
Product B        20 min        0 min      45 min
Product C        40 min       30 min       0 min
```
- Consumed by the "minimize changeover" scheduling strategy (§3I) to reorder a resource's queue
  and minimize **total processing + setup + cleaning time**, not just maximize raw utilization.

## 3K. Process-Manufacturing Planning Specifics

**Function / Features**
- Campaign scheduling: the "group by product family" / "campaign production" strategy (§3I)
  applied specifically to process orders sharing a recipe, so the floor runs White → White →
  Yellow → Yellow → Dark instead of alternating — a sequencing rule, not new storage.
- Batch-size planning: MRP (§3D) plans directly against **PP's own** `pp_recipes.batch_size` and
  expected yield % — no cross-module call needed, since Recipe master data is PP's own (§3D).
  Recipe versioning (§3D: one active version per product) is what keeps a historical plan
  traceable to the version it was computed against, not a read of another module's data.
- Tank/utility capacity is not a special case — it is `pp_resources.type = tank` /
  `type = utility` flowing through the same §3F/§3G capacity engine; "tank must be empty before
  next batch" is a sequence constraint (§3L below), not a separate tank-scheduling engine.

## 3L. Production Constraints (checks surfaced by §3M, not a separate storage concept)

**Function / Features** — a constraint-check service run at MRP (§3D), Capacity (§3F), and
Detailed Scheduling (§3H) time, each check reading an existing module rather than duplicating
its data:
- **Material** — `InventoryService::checkAvailability()`.
- **Resource** — Schedule's `AvailabilityService::isFree()` + MES equipment status
  (`MES_SPECS.md` §3D/§3M).
- **Sequence** — routing/phase predecessor rule, same enforcement MES's own shop-floor UI applies
  (`MES_SPECS.md` §3G "cannot start until predecessor is completed").
- **Tank** — `pp_resources.type = tank` availability (§3E/§3G).
- **Quality** — open `MES.mes_qc_holds` (`MES_SPECS.md` §3L) blocking a lot/serial from being
  planned as an input.
- **Labor** — `HCM` certification/skill reference (read-only), blocking a scheduled operation
  from an uncertified resource assignment.
- Each failed check writes a §3M exception; none of these are new tables, they are read checks
  against the module that already owns the fact.

## 3M. Planning Exception Center (`pp_exceptions`, read model)

**Function / Features**
```text
⚠ 12 material shortages
⚠ 4 capacity overloads
⚠ 3 late production orders
⚠ 2 missing routings
⚠ 5 orders without available resources
⚠ 1 critical machine maintenance conflict
⚠ 7 purchase orders arriving late
```
- One row per detected condition (type, severity, affected `pp_planned_orders`/`pp_mps_lines`
  reference, affected material/resource reference, detected_at, status open/acknowledged/
  resolved). Regenerated by each engine's constraint checks (§3L) and capacity calculation
  (§3F), not maintained by hand.
- Drill-down: **Problem → Affected Order → Affected Material/Resource → Suggested Actions** (the
  action list is the same options §3F's overload rule already enumerates, generalized per
  exception type).

## 3N. Scenario Planning & What-if (Phase 3)

**Function / Features**
- `pp_scenarios` (name, base scenario = baseline when null, created_by, status). A scenario is
  created by copying the current baseline's relevant rows with a new non-null `scenario_id`
  (§5 Scenario Isolation) — every §3B–§3M engine already reads/writes `scenario_id`-scoped data,
  so running MRP/Capacity/Scheduling "inside" a scenario requires no engine change, only scoping
  the query.
- Comparison view across scenarios (production total, capacity %, overtime hours, material
  shortage count, late-order count) — a read model over the same tables, filtered by
  `scenario_id`.
- **What-if rescheduling** for a single order/date ("can we deliver 2 days earlier?"): runs §3D/
  §3F/§3H against a throwaway scenario seeded from baseline, reports feasibility and the specific
  actions required (overtime, resource move, earlier purchase), same shape as the source brief's
  §19 example — never commits unless the planner explicitly releases the result.

## 3O. Production Planning Dashboard

**Function / Features**
```text
Production Plan — September 2026
────────────────────────────────
Demand       125,400 units
Planned      121,000 units
Gap            4,400 units

Capacity        87%
Material        94% available
On-time         93%

CAPACITY
Cutting     █████████░ 89%
Welding     ██████████ 103%  ⚠
Assembly    ████████░░ 82%
Packaging   █████████░ 91%

EXCEPTIONS
⚠ 12 material shortages
⚠ 4 capacity overloads
⚠ 3 late orders
✓ 82 orders ready
```
- Composed entirely from `StatCard`/`Panel`/`DataTable` (`CLAUDE.md` §9D) over §3B/§3D/§3F/§3M —
  no dashboard-only storage.

---

# 4. Storage

> Tables under tenant `PP` PostgreSQL schema. External references are read via the owning
> module's service contract, never a direct cross-schema join in application code.

**Master / parameter tables**
- `PP.pp_item_planning_params` (§3A) — references `INVENTORY.products`
- `PP.pp_boms`, `PP.pp_bom_lines`, `PP.pp_recipes`, `PP.pp_recipe_ingredients` (§3D) —
  reference `INVENTORY.products`; referenced cross-schema by `MES.mes_prod_order_hdrs`
  (`bom_id`/`recipe_id`) and `MES.mes_process_phases` (`recipe_id`), per `MES_SPECS.md` §3B/§3F
- `PP.pp_resource_groups`, `PP.pp_resource_group_members`, `PP.pp_resources` (§3E)
- `PP.pp_changeover_matrix` (§3J)
- `PP.pp_demand_forecasts` (§3B)

**Planning tables** (all carry nullable `scenario_id` — §5)
- `PP.pp_demand_hdrs`, `PP.pp_demand_lines` (§3B)
- `PP.pp_mps_hdrs`, `PP.pp_mps_lines` (§3C)
- `PP.pp_mrp_runs`, `PP.pp_planned_orders` (§3D) — `subject_type`/`subject_id` on release points
  into `MES.mes_prod_order_hdrs`, `PURCHASE.pur_req_hdrs`, or an `INVENTORY` transfer request
- `PP.pp_capacity_plans` (§3F)
- `PP.pp_schedule_ops` (§3H)

**Read-model / cross-cutting tables**
- `PP.pp_exceptions` (§3M)
- `PP.pp_scenarios` (§3N, Phase 3)
- `PP.pp_audit_logs` — append-only, same per-module convention as `MES.mes_audit_logs`
  (`MES_SPECS.md` §3U), for governance-sensitive edits (e.g. a firmed MPS cell overridden, a
  planned order manually rescheduled past its exception).

**Custom fields:** `pp_item_planning_params`, `pp_boms`, `pp_recipes`, `pp_planned_orders`, and
`pp_mps_lines` are registered in `CUSTOMFIELDS.field_defs`, same pattern as every other Core
module's master/transaction rows.

**Object File** (per `CLAUDE.md` §7B): PP owns no top-level R2 folder — any attachment (forecast
import file, scenario export) routes through **DMS** (`DMS/PP/...`) with a `subject_type`/
`subject_id` pointer, same as WNE/HCM/Sales/MES.

---

# 5. Technical Notes

- **Planning owns material composition; execution owns floor identity.** BOM/Recipe (material
  composition) is **PP's own** master data (§3D) — MRP explodes it directly, no service call
  needed. Work Center/Machine/Station identity, Routing/Process-Phase execution steps, the
  Production Order record, and the event ledger all remain **MES's** — PP calls `MesService`
  contracts (`listResources`, `createProductionOrder`) for those, and MES calls `PpService`
  contracts (`getActiveBom`, `getActiveRecipe`, `scaleRecipe`) for PP's, exactly the two-way
  boundary `MES_SPECS.md` §5 already holds with Inventory/HCM (never duplicate another Core
  module's table). This is the load-bearing boundary decision in this spec, revised once from an
  earlier draft that kept BOM/Recipe in MES — see §7 Open Items.
- **Scenario isolation is one nullable column, not shadow tables.** Every planning table in §4
  carries `scenario_id`; `NULL` is the baseline every non-scenario query defaults to. This keeps
  the source brief's §11 requirement ("scenarios must not affect actual inventory/orders")
  structural rather than convention-based, and avoids a second copy of every planning table per
  scenario.
- **The Planning/Detailed-Scheduling boundary with MES §3Q is resolved in §3H**: PP proposes the
  schedule (finite/infinite toggle, sequencing strategy, changeover optimization, campaign
  grouping, Gantt); MES's own §3Q re-sequences only the live dispatch queue in front of an
  operator as floor conditions change. Read §3H before touching either spec's scheduling logic.
- **Own numbering series.** `SYSCONFIG.config_snums`, `snum_code = PP_PLAN_LASTID`, distinct from
  MES's `MES_MO_LASTID` — PP never assigns an MES order number, MES never assigns a PP planned-
  order number.
- **Frontend**: standard `AppLayout` admin chrome throughout (unlike MES's dedicated Shop Floor
  layout) — MPS grid, Capacity board, Gantt, and Exception Center are all planner desk tools, not
  shop-floor touch UI. Composed from `DataTable`/`Panel`/`StatCard`/`StatusBadge`
  (`CLAUDE.md` §9D).
- **Append-only**: `pp_audit_logs` only, same discipline as `MES.mes_audit_logs`/
  `MES.mes_prod_events`; PP's own planning tables (`pp_mps_lines`, `pp_planned_orders`, etc.) are
  ordinary mutable rows since replanning is expected to overwrite prior plan versions — the
  event/audit trail lives in `pp_audit_logs`, not in row history.
- **Menu/permission codes**: `menu.perm:PP_*` (e.g. `PP_MPS`, `PP_CAPACITY`, `PP_SCHEDULE`,
  `PP_EXCEPTIONS`) via SYSCONFIG trustee middleware, same as every other module (`CLAUDE.md` §4).
- **Plan gating**: `module:PP` middleware + `config/tenant_modules.php` entry — added, `full`
  plan only; see §7.

---

# 6. Build Order

> Recommended sequence for implementing this module's own pieces. See `CLAUDE.md` §5 for where PP
> sits in the platform-wide build order (depends on Inventory, Sales, Schedule, HCM; sequenced
> *before* MES, since MES's Process Phases master data FKs into this module's own `pp_recipes` —
> BOM/Recipe is PP's own, no MES dependency there. Equipment/resource identity and
> Routing/Process-Phase timing standards (§3E/§3F) still depend on MES, so those pieces of PP
> naturally lag MES's own equipment master data — see §7).

1. **Item Planning Parameters (§3A)** — no dependents within PP, needed before demand/MRP can
   compute lot sizes or safety stock.
2. **Demand Aggregation (§3B)** — depends on Sales/Inventory read access; nothing downstream
   works without demand rows to plan against.
3. **BOM/Recipe Master Data & MRP Engine incl. release (§3D)** — BOM/Recipe explosion needs only
   `INVENTORY.products` and `InventoryService`; unlike an earlier draft of this spec, it has **no
   MES dependency** for netting — this is PP's own master data now. Only the release sub-action
   depends on `MesService::createProductionOrder` (production-type), `PurchaseService`
   (purchase-type), or `InventoryService` (transfer-type) — build explosion/netting first,
   release last. This is the first point PP produces something actionable (a planned order), so
   prioritize it over MPS's grid UI if a tradeoff is needed.
4. **MPS (§3C)** — the planner-facing grid over §2/§4's data; can follow §3D since it is
   presentation plus firm/release actions on the same rows.
5. **Resource/Resource Group reference (§3E) and Capacity Planning (§3F/§3G)** — depends on
   `MesService::listResources` and Schedule's `AvailabilityService`; this is the one PP slice
   that genuinely waits on MES (its Work Center/Machine/Station master data, `MES_SPECS.md` §3D
   step 1), even though PP as a whole is sequenced before MES in `CLAUDE.md` §5 — the dependency
   runs both directions at this fine a grain, see §7.
6. **Planning Exception Center (§3M)** — depends on §3D/§3F's constraint checks (§3L) having
   something to flag; build the checks inline with §3–§5 above, then aggregate here.
7. **Detailed Scheduling (§3H), Scheduling Rules (§3I), Changeover Matrix (§3J)** — depends on
   §3E/§3F existing; this is where the MES §3Q boundary (§3H's resolution paragraph) must already
   be settled in code, not just in this document.
8. **Process-manufacturing specifics (§3K)** — thin layer over §3D (recipe read) and §3I/§3J
   (campaign strategy); ships alongside whichever production model (discrete/process) the first
   real tenant needs, same "build whichever model is needed first" posture as
   `MES_SPECS.md` §6 step 5.
9. **Dashboard (§3O)** — pure read model, ships once §3B/§3D/§3F/§3M have real data.
10. **Scenario Planning & What-if (§3N)** — Phase 3; requires every earlier engine to already
    respect `scenario_id` scoping, so retrofit is expensive — read §5's scenario-isolation note
    before starting any earlier step if Phase 3 is likely to be pulled forward.

---

# 7. Open Items

- [x] **`CLAUDE.md` §4/§5/§7A registration and `config/tenant_modules.php` entry** — schema `PP`
      added to §4/§7A's authoritative list, a build-order entry added to §5, and `PP` unlocked on
      the `full` plan only, same placeholder posture MES itself started from pending a real
      manufacturing-tenant decision.
- [x] **BOM/Recipe ownership — moved to PP.** Supersedes this spec's original decision (which
      kept BOM/Recipe in MES and had PP call `MesService::resolveBom`/`resolveRecipe`): material
      composition is planning master data, so it now lives here (§3D) as `pp_boms`/`pp_recipes`.
      MES keeps Routing/Process-Phases (`MES_SPECS.md` §3E/§3F — the execution-step sequence,
      renamed from `mes_recipe_phases`/`mes_recipe_parameters` to `mes_process_phases`/
      `mes_process_parameters` and now FK-ing cross-schema into `PP.pp_recipes`) and calls
      `PpService::getActiveBom`/`getActiveRecipe`/`scaleRecipe` for composition data — the
      mirror image of PP calling `MesService` for equipment identity. Both `MES_SPECS.md` and
      this spec were updated together so neither document contradicts the other.
- [x] **Platform build-order placement relative to MES — reversed.** Now that BOM/Recipe lives
      here and `MES.mes_process_phases` FKs into `PP.pp_recipes`, **PP is sequenced before MES**
      at the coarse `CLAUDE.md` §5 module-list level (the previous entry's reasoning is
      superseded — it depended on the now-obsolete `MesService::resolveBom`/`resolveRecipe`
      contracts). This is not a strict one-way dependency at the fine-grained level, though: PP's
      own Resource/Capacity engines (§3E/§3F, this spec's §6 step 5) still wait on MES's Work
      Center/Machine/Station master data and Routing/Process-Phase timing standards. A solo dev
      should read this as "build PP's Item Params/BOM-Recipe/Demand/MPS/MRP and MES's Work-Center
      identity in either order (neither depends on the other), then MES's Routing/Process-Phases
      (needs PP's recipes) and PP's Resource/Capacity/Scheduling (needs MES's equipment) after,"
      not as a single linear module-after-module sequence — `CLAUDE.md` §5's one-line-per-module
      format can't express that nuance, so it states the coarse "PP before MES" placement and this
      note is the caveat.
- [x] **`PpService::getActiveBom`/`getActiveRecipe`/`scaleRecipe` — implemented.** Built as part
      of §3D's MRP engine, whose own explosion step is the first real caller (not a speculative
      stub): `getActiveBom`/`getActiveRecipe` resolve the one active version for a product,
      `scaleRecipe` wraps `RecipeService::scale()` by recipe id for the eventual MES caller.
      `MesService::listResources`/`createProductionOrder` remain unimplemented — MES itself
      isn't built (`MES_SPECS.md` §7) — so `PlannedOrderService::release()` guards a
      production-type planned order with a clear "MES not available yet" message rather than
      calling a stub; that half of this item stays open until MES ships.
- [x] **`.id.md` translation** — this document itself; every module spec except MES (the newest)
      has an `.id.md` sibling, and now PP does too.
- [x] **MPS (§3C) built as presentation + firm/release over §3D's existing planned orders, per
      this spec's own §6 build-order note — not a rework of MrpService into period-bucketed
      netting.** `pp_mps_lines.is_frozen` is an edit lock on `planned_qty` only; it does not (yet)
      block MRP from replanning a period, since §3D nets one planned order per product per *run*,
      not per period. The real "exclude from automatic MRP regeneration" mechanism is
      `PlannedOrderService::firm()`/`unfirm()` (the `status = 'firmed'` value this spec's own SQL
      already reserved) — `MrpService::explode()` now leaves a firmed baseline order untouched
      and skips creating a new row for that product, while still exploding its BOM/recipe so
      dependent demand on its components isn't lost. A consequence worth remembering: because
      §3D isn't period-bucketed, a period cell's firm/release controls are only active when a
      planned order's `need_by_date` actually falls inside that period; true multi-period MPS-vs-
      MRP reconciliation is future work once/if MRP itself becomes period-bucketed. `pp_mps_lines`
      is in §4's `CUSTOMFIELDS.field_defs` list, but no per-cell custom-field UI was built —
      editing custom fields one grid cell at a time is bad UX; deferred until a real use case
      asks for it.
- [x] **Resource & Resource Group Reference (§3E) built as flat master data — `pp_resources`
      CRUD (tool/tank/utility/warehouse, no custom fields — not in §4's registry) and
      `pp_resource_groups` CRUD with a synced `members` list (same header+lines sync pattern as
      `BomService`).** `pp_resource_group_members.resource_ref_id` is genuinely polymorphic by
      `resource_type` (its meaning depends on the type), so it gets no DB FK even for the
      `pp_resource` case — validated in the Request's `withValidator` instead, same discipline
      `pp_planned_orders.source_type`/`source_id` already uses. Only the `pp_resource` type is
      checkable today; `mes_work_center`/`mes_machine`/`mes_station` stay informational/
      app-trusted since MES isn't built yet — the member list-input UI makes this explicit
      (labels those options "informational — MES not built yet" and swaps the picker for a bare
      ID field). `pp_resources`/`pp_resource_groups`/`pp_resource_group_members` carry no
      `created_at`/`updated_at` columns, matching this spec's own SQL DDL exactly, so their
      Eloquent models set `$timestamps = false` — the one thing that failed on first pass
      (caught by the Feature test, not by `php -l`).
- [x] **Capacity Planning — RCCP (§3F) built as planner-entered flat CRUD, not the automatic
      pipeline this spec's own §3F Function/Features bullet describes.** That bullet names two
      sources for `required_hours`/`available_hours`: `MesService` routing/recipe-phase standard
      times (MES isn't built — no standard-time data exists anywhere to explode MPS/MRP output
      against) and Schedule's `AvailabilityService` (which only answers "is this exact slot free
      right now?" via `isFree()`/`findConflicts()` — there is no "how many hours are available in
      this date range?" aggregator to call). Building either integration now would mean writing
      against data or an API shape that doesn't exist. Since this spec's own Rules/Logic already
      frames Phase 1 as "rough-cut... load vs. available is informational," a planner-entered
      `required_hours`/`available_hours` pair per resource-or-group/period satisfies that bar
      honestly — `CapacityPlanService::loadPct()`/`isOverloaded()` (threshold via
      `SYSCONFIG.config_consts` `PP.CAPACITY_OVERLOAD_THRESHOLD_PCT`, default 100) are real,
      just computed from planner input instead of derived automatically. The overload flag is
      also **not** written as a `pp_exceptions` row — §3M isn't built yet — it's a computed
      badge in the Index page only, same "derive it inline, don't fabricate the sink" choice
      made for MPS's demand-shortfall flag (§3C). `resource_group_id` gets a real FK
      (`pp_resource_groups` exists in-schema); `resource_ref_id` stays unconstrained
      (polymorphic by `resource_type`, §3E's discipline); the Request enforces group-XOR-
      resource (exactly one target), tighter than the DB CHECK's permissive "at least one."
- [ ] **Advanced constraint solver (Phase 3)** — if a real tenant's joint material+resource+
      sequence+tank+quality+labor optimization workload turns out to need a dedicated solver
      library/runtime, evaluate microservice extraction against `CLAUDE.md` §2's criteria at that
      time; nothing today meets the bar.
