# Inventory Module
## Inventory Management System — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every vertical that touches a physical good — and even some that don't (Legal firms still
track office supplies, evidence custody, exhibit inventory) — eventually needs to answer three
questions: *what do we have, where is it, and what is it worth.* Left unsolved centrally, this
repeats the exact anti-pattern WNE/DMS/CRM were built to avoid:

- Each vertical invents its own "stock" concept — no shared notion of a product, a location,
  or a movement, so nothing is comparable or reportable across the platform.
- No single source of truth for on-hand quantity — reports drift, double-selling/double-issuing
  becomes possible, and reconciliation is manual.
- No consistent costing method — valuation for accounting/reporting purposes has to be correct
  and auditable (FIFO or Weighted Average), not eyeballed.
- No reusable barcode/scan workflow — every module that touches physical goods would otherwise
  build its own scanning UI.
- No common place to trigger "low stock" / "cycle count due" notifications — this is exactly
  the kind of event WNE already exists to route, so Inventory must plug into it rather than
  building a parallel notification path.

**Client requirements:**
- Multi-tenant aware, same DB-per-tenant isolation as every other Core module (no `tenant_id`
  column — see `CLAUDE.md` §4/§7).
- Must work **fully standalone** — a tenant can run Inventory with nothing else installed
  (simple product + stock tracking), since it's sellable as its own line item, exactly like DMS
  and Schedule.
- Must also integrate cleanly when other Core modules are present: **CRM** (vendor/customer are
  Partners, not a separate Inventory contact table), **Purchase** (a PO-linked Goods Receipt
  calls `InventoryService::receive()` directly to post the physical stock movement — §5),
  **Sales** (a shipped Delivery calls `InventoryService::issue()` directly to post the physical
  stock movement — §5), **DMS** (packing lists, QC certificates, supplier invoices attach via
  the existing facade), **WNE** (low-stock alerts, cycle-count reminders, receipt-approval
  workflow), **Schedule** (dock appointments, cycle-count scheduling).
- Perpetual inventory, not periodic — every quantity change must be a recorded, immutable
  movement, so on-hand balances are always derivable/auditable, not just a mutable counter.
- Costing must be correct and swappable per tenant (FIFO or Weighted Average) since this feeds
  accounting/reporting, not just an operational nicety.
- Barcode scanning must be a first-class input method from day one (receipt, issue, transfer,
  cycle count), since warehouse floor speed is a major selling point.
- Multi-warehouse, multi-location from day one — even a tenant with one warehouse benefits from
  the same bin/zone model a multi-site tenant needs; no rework later.

# 2. Goals

> Designated features. **MVP-first** — ship something sellable fast; Operational features are
> the immediate fast-follow; Advanced/Optimization are explicitly deferred.

## MVP — Core (ship first, quick implementation)
- **Product Master** — SKU, description, category, UoM (+ conversions), default costing
  method, barcode(s), reorder point (simple, no forecasting).
- **Warehouse / Location Master** — warehouses, and locations (bins) inside them,
  hierarchical enough to support put-away later without a schema change.
- **Goods Receipt** — receive stock in (from a vendor, or unlinked "opening balance"), creates
  a stock ledger entry + a valuation layer.
- **Goods Issue** — issue stock out (to a customer, a cost center, or unlinked consumption),
  consumes valuation layers per the tenant's costing method.
- **Transfers** — move stock between locations/warehouses, a paired issue+receipt under one
  transaction so it never appears "missing" mid-transfer.
- **Adjustments** — correct on-hand quantity (count variance, damage, write-off) with a
  mandatory reason code — never a silent quantity edit.
- **Stock Card** — the immutable ledger view per product/location: running balance, every
  movement, reference document, always reconstructable from the ledger, never a mutable field.
- **Inventory Valuation** — on-hand value by product/warehouse/category, using the tenant's
  chosen costing method.
- **FIFO / Weighted Average Cost** — selectable per tenant (or per product, overridable),
  correct cost-layer consumption on every issue.
- **Barcode support** — scan-to-receive, scan-to-issue, scan-to-count; product barcode(s) with
  an alternate/case-pack barcode option.

## Operational (v1.1 — immediate fast-follow, still pre-Advanced)
- **Batch / Lot tracking** — group stock by batch/lot with expiry, for products that need it
  (pharma-adjacent, food-adjacent, or any regulated goods a future vertical might bring).
- **Serial Number tracking** — unit-level identity for high-value/warranty-tracked items.
- **Reservations** — soft-allocate stock against a pending order (Sales, or any vertical) so
  available-to-promise is accurate without physically moving anything yet.
- **Picking** — generate a pick list from reserved/ordered lines, simple single-order picking
  (not wave/zone — see Advanced).
- **Packing** — confirm picked items into a shipment package (carton/pallet), capture
  weight/dimensions for shipping.
- **Shipping** — hand-off to carrier, tracking number capture, ships-confirmed status that
  triggers the final Goods Issue.
- **Cycle Counting** — scheduled partial counts (by location/category/ABC class) instead of
  full physical inventory, variance review → Adjustment.
- **Multi-warehouse** — already structurally supported in MVP; this tier adds cross-warehouse
  reporting/rollup views and warehouse-level reorder policies.
- **Put-away rules** — simple rule set (by product category → default zone/location) so
  receiving staff aren't manually choosing a bin every time.

## Advanced — **Future Version** (do not build now)
- **Wave / Zone / Cluster Picking** — batched, multi-order picking optimized by warehouse
  layout; needs real order volume to justify the complexity.
- **FEFO** (First-Expired-First-Out) — an alternate consumption strategy layered on top of the
  Batch/Lot expiry data already captured in Operational; deferred because FIFO/Average covers
  MVP costing needs and FEFO is a picking-strategy refinement, not a costing necessity.
- **Cross-docking** — receive-and-ship without put-away; a flow-optimization feature that
  needs real throughput to justify.
- **Quality Management** — inspection holds, QC pass/fail gating before stock becomes
  available; genuinely valuable but a distinct sub-domain (inspection plans, defect codes) —
  build once a vertical actually requires regulated receiving.
- **Consignment** — vendor-owned stock held on-site, owned-elsewhere-but-tracked-here; a
  distinct ownership model layered on the existing ledger, deferred until a client asks.
- **Landed Cost** — allocate freight/duty/customs into product cost after receipt; valuable for
  import-heavy tenants, but the MVP valuation layers already support an additive
  "cost adjustment" entry later without a schema break.
- **Dock Scheduling** — appointment slots for inbound/outbound trucks; naturally reuses the
  **Schedule** module's Resource/Availability engine rather than reinventing it — build as a
  thin Inventory-specific Resource type + booking flow once dock volume is real.

## Optimization — **Future Version** (AI/analytics layer, post-launch)
- **AI Forecasting** — demand forecasting per product/warehouse to drive reorder suggestions;
  natural fit for **AIInsights Core**'s pattern (per-tenant scoped, cost-effective via prompt
  caching) once there's enough historical ledger data to forecast against.
- **Slotting Optimization** — suggest optimal bin placement from pick-frequency data.
- **Anomaly Detection** — flag unusual shrinkage/adjustment patterns for review.
- **Predictive Replenishment** — auto-generate suggested POs from forecast + reorder policy.
- **Warehouse Performance Analytics** — pick/pack/ship cycle-time dashboards, accuracy rates.
- All Optimization features are read/analysis layers **on top of** the MVP stock ledger — none
  require a schema change to the ledger itself, only additive tables (forecasts, suggestions,
  anomaly flags) and, for the AI features, the same Claude API + ZDR posture already flagged
  for AIInsights Core.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard (Inventory Overview)

**Function / features**
- At-a-glance stock health: total SKUs, total on-hand value, low-stock count, out-of-stock
  count, pending receipts, pending shipments, open cycle counts.
- "Needs attention" queue: below-reorder-point products, expiring-soon batches (once
  Operational ships), open count variances awaiting review.
- Quick actions: new Goods Receipt, new Goods Issue, new Transfer, scan-to-count.

**Layout**
- Top: summary cards (On-Hand Value, Low Stock, Out of Stock, Pending Receipts/Shipments).
- Main: tabbed data table (shared component per `DESIGN.md`) — "Low Stock" | "Recent
  Movements" | "Pending Documents" | "Open Counts".
- Every row uses the shared **Status Rail**: `danger` = out of stock / negative variance,
  `warning` = below reorder point / expiring soon, `success` = healthy stock, `info` =
  system-generated movement (e.g. auto put-away), neutral = plain in-stock item.

**Rules / logic**
- All queries scoped to the tenant DB automatically (DB-per-tenant, no app-level filter).
- "Low stock" compares current `stock_balances.qty_on_hand` against `products.reorder_point`
  per warehouse (or globally if not warehouse-specific).
- Out-of-stock and negative-variance items surface first regardless of sort.

## 3B. Product Master (Entry)

- Fields: SKU (unique per tenant), name, description, category (`product_categories`,
  tenant-editable tree), base UoM, additional UoMs + conversion factors, costing method
  (`fifo` / `average`, tenant default, overridable per product), reorder point, reorder
  quantity (simple, no forecasting in MVP), primary barcode + alternate barcodes
  (`product_barcodes`), is_active, tracking mode (`none` / `batch` / `serial` — Operational,
  present in schema from day one, enforced once Operational ships), custom fields via the
  existing `CUSTOMFIELDS` schema.
- List view: shared data table, Status Rail reflects current aggregate stock health for that
  product across warehouses.
- Detail view: tabs — Overview, Stock by Location, Stock Card (3H), Barcodes, Custom Fields.

**Rules / logic**
- SKU uniqueness enforced tenant-wide (DB-per-tenant means no cross-tenant collision concern).
- Changing `costing_method` on a product with existing valuation layers is blocked — a costing
  method change requires closing out existing layers first (prevents silently corrupting
  historical valuation); surfaced as a clear error per `DESIGN.md` voice guidance.
- Deactivating a product blocks new receipts/issues but never hides historical ledger entries.

## 3C. Warehouse & Location Management (Entry)

- `warehouses`: name, address, is_active.
- `locations`: `warehouse_id`, `parent_location_id` (self-referencing — supports zone → aisle →
  bin hierarchy without a future schema change), code, type (`zone` / `bin` / `staging` /
  `dock` — extensible lookup, not hardcoded enum), is_active.
- Tree CRUD view, same interaction pattern as DMS's Folder Management (3D in `DMS_SPECS.md`).

**Rules / logic**
- A location cannot be deleted while it holds on-hand stock (per `stock_balances`) —
  reassignment/transfer required first, same integrity principle as DMS's folder deletion rule.

## 3D. Goods Receipt (Entry / Engine)

- Header: warehouse, receipt date, source (`subject_type`/`subject_id` — optional polymorphic
  link to a Purchasing/vertical PO, or a vendor Partner from **CRM** for a direct receipt with
  no PO, or blank for "opening balance"), reference number, status (`draft` → `posted`).
- Lines: product, batch/lot (if tracked — Operational), quantity, UoM, unit cost, destination
  location (defaults from Put-away rules, 3P — Operational; manual in MVP if rules aren't set
  up yet).
- **On post:** creates one `stock_ledger` entry per line (movement type `receipt`, signed
  positive), creates a new valuation layer (`stock_valuation_layers`) at the received unit
  cost, updates `stock_balances` (denormalized current-quantity cache), fires
  `inventory.goods_received` event.
- Barcode scan mode: scan product barcode → auto-fill line, scan location barcode → set
  destination, quantity by scan-count or manual entry.

**Rules / logic**
- Posting is the only action that touches the ledger — `draft` receipts can be edited freely,
  `posted` receipts are immutable (correct via a reversing Adjustment, never an edit), same
  audit-integrity principle DMS applies to its access log.
- When created via `InventoryService::receive(...)` from Purchase's Goods Receipt
  (`PURCHASE_SPECS.md` §3E), `subject_type = 'purchase.pur_receipt_hdrs'` and unit cost
  defaults from the originating PO line price. Inventory does not re-validate PO/GR/Invoice
  matching itself — the three-way match stays entirely Purchase's job
  (`PURCHASE_SPECS.md` §3F); Inventory only needs a valid quantity and cost to post its own
  ledger correctly.

## 3E. Goods Issue (Entry / Engine)

- Header: warehouse, issue date, destination (`subject_type`/`subject_id` — optional link to a
  Sales order/vertical record, a customer Partner from CRM, or blank for internal consumption),
  reason (for unlinked issues: consumption, sample, write-off-pending-adjustment-review),
  status (`draft` → `posted`).
- Lines: product, batch/lot (if applicable — FIFO within batch honored), quantity, UoM, source
  location.
- **On post:** creates `stock_ledger` entry (movement type `issue`, signed negative), consumes
  valuation layers per the product's costing method (oldest-layer-first for FIFO; recomputed
  blended rate for Average), updates `stock_balances`, fires `inventory.goods_issued` event.
- Blocks posting if requested quantity exceeds available (on-hand minus reserved) at that
  location — clear error per `DESIGN.md` voice: *"Only 12 units of SKU-1042 available at
  Warehouse A / Bin 03. Reduce quantity or choose another location."*

**Rules / logic**
- When created via `InventoryService::issue(...)` from Sales's Delivery Engine
  (`SALES_SPECS.md` §3H), `subject_type = 'sales.dlv_hdrs'` and the same over-issue block above
  applies — a delivery cannot ship more than is actually available, surfaced back to Sales as a
  rejected `shipped` transition rather than a partial/incorrect ledger entry. Inventory does
  not validate order legitimacy, pricing, or customer status — that stays entirely Sales's job;
  Inventory only needs a valid quantity and location to post its own ledger correctly, the same
  division of responsibility already established for Purchase's Goods Receipt
  (`INVENTORY_SPECS.md` §3D).

## 3F. Transfers (Entry)

- Header: source warehouse/location, destination warehouse/location, transfer date, status
  (`draft` → `in_transit` → `completed`, the middle state only meaningful for cross-warehouse
  transfers with real transit time; same-warehouse bin transfers can skip straight to
  `completed`).
- Lines: product, batch/lot, quantity, UoM.
- **On post:** a single transaction writes a paired `stock_ledger` issue-at-source +
  receipt-at-destination — modeled as one `transfer` movement type (not two independent
  documents) so the stock card reads clearly as "moved," not "vanished then reappeared."
- Valuation layer moves with the stock (cost basis unchanged by a transfer — only issues to
  outside the company consume/realize cost).

## 3G. Adjustments (Entry)

- Header: warehouse/location, adjustment date, reason code (`count_variance` / `damage` /
  `expiry` / `theft_loss` / `correction` / `other` — tenant-editable lookup), reference (e.g.
  linked Cycle Count, 3O).
- Lines: product, batch/lot, system quantity (auto-filled from current balance), counted/actual
  quantity, variance (computed), unit cost basis for the variance (uses current valuation layer
  cost — a positive adjustment creates a new layer at that cost, a negative adjustment consumes
  layers same as an Issue).
- **On post:** `stock_ledger` entry (movement type `adjustment`, signed by variance direction),
  updates `stock_balances`, fires `inventory.stock_adjusted` event — large/negative adjustments
  above a configurable threshold can optionally route through a **WNE** approval workflow
  (`workflow_code = inventory.adjustment_approval`) before posting, same opt-in pattern CRM
  uses for lead qualification.

## 3H. Stock Card (View / Report)

- Per product (× warehouse, or × location, or × batch/lot if tracked): chronological,
  immutable list of every `stock_ledger` entry — date, movement type, reference document,
  quantity in/out, running balance, unit cost, running value.
- **Always reconstructable from `stock_ledger` alone** — `stock_balances` is a cache for fast
  reads, never the source of truth; a rebuild job can regenerate balances from the ledger if
  they ever drift (integrity safety net).
- Filters: date range, movement type, warehouse/location, batch/lot.
- Export to CSV/PDF for audit purposes (legal-vertical clients will expect this for
  discoverability, same posture as DMS's audit trail).

## 3I. Inventory Valuation Engine

- On-hand value report: by product / category / warehouse, using each product's active costing
  method, summed from open (unconsumed) `stock_valuation_layers`.
- Valuation snapshot: point-in-time value as of a given date (for period-close reporting),
  computed by replaying the ledger up to that date — no separate "closing" process needed since
  the ledger is the source of truth.

## 3J. FIFO / Weighted Average Cost (Engine)

- **FIFO**: `stock_valuation_layers` per receipt, `remaining_qty` decremented in
  receipt-date order on each issue; issue cost = weighted cost of the layers actually consumed
  (a single issue can span multiple layers if it exhausts one).
- **Weighted Average**: no discrete layers consumed in order — instead a running
  `avg_cost` per product/warehouse recalculated on every receipt
  (`new_avg = (old_qty*old_avg + received_qty*received_cost) / (old_qty+received_qty)`); issues
  simply use the current `avg_cost` at time of posting.
- Both methods write to the same `stock_ledger`/`stock_valuation_layers` tables — the method
  only changes *how consumption is calculated* on issue, not the schema, so switching a
  tenant's default (for new products going forward) requires no migration.
- Exposed as `CostingService::costReceipt()`, `CostingService::costIssue()` — an interface
  (`CostingStrategyInterface`) with `FifoStrategy`/`AverageStrategy` implementations, so a
  future strategy (e.g. Standard Cost) is additive, same driver pattern as WNE's
  `ChannelDriverInterface`.

## 3K. Barcode Engine

- `product_barcodes`: `product_id`, barcode value (unique per tenant), type
  (`primary`/`case_pack`/`alternate`), unit multiplier (e.g. a case-pack barcode scans as
  ×24 of the base UoM).
- Scan input is accepted as a plain text field wherever a product/location lookup happens
  (Receipt, Issue, Transfer, Cycle Count) — no special hardware dependency, works with any
  USB/Bluetooth HID scanner or a mobile camera scan via the existing Vue frontend.
- Location barcodes reuse the same table pattern (`location_barcodes`) so bins can be
  physically labeled and scanned during put-away/picking.

## 3L. Batch / Lot Tracking — *Operational*

- `stock_batches`: product_id, batch/lot number, expiry_date (nullable), manufacture_date
  (nullable), supplier reference.
- `stock_ledger` and `stock_valuation_layers` carry an optional `batch_id` — when a product's
  `tracking_mode = batch`, every receipt/issue/transfer/adjustment line requires one.
- Expiry-soon batches surface on the Dashboard (3A) with a `warning` Status Rail; expired
  batches with a blocking warning on Issue (overridable with a reason, logged).

## 3M. Serial Number Tracking — *Operational*

- `stock_serials`: product_id, serial number (unique per tenant), current status
  (`in_stock` / `reserved` / `issued`), current location.
- For `tracking_mode = serial` products, each unit is its own row — receipt creates N serial
  rows for quantity N, issue must specify which serial(s), never just a quantity.

## 3N. Reservations — *Operational*

- `stock_reservations`: product_id, batch/serial (if applicable), quantity, warehouse/location
  (or unassigned-pending-pick), `subject_type`/`subject_id` (polymorphic link to the order that
  requested it), status (`active` / `fulfilled` / `released`), expiry (auto-release if not
  fulfilled by a configurable window).
- Available-to-promise = `stock_balances.qty_on_hand` − active reservations at that
  product/location — exposed as `InventoryService::checkAvailability()`, same
  "one reusable service other forms call" pattern as Schedule's `AvailabilityService`.

## 3O. Picking — *Operational*

- `pick_lists` / `pick_list_lines`: generated from one or more reservations, grouped by
  warehouse, assigned to a picker, sequenced by location for walk efficiency (simple location
  sort in v1 — true path optimization is Advanced/Wave picking).
- Mobile-friendly scan-to-pick flow: scan location barcode → scan product barcode → confirm
  quantity → line marked picked.
- Completing all lines on a pick list moves it to `packing`-ready status.

## 3P. Packing & Shipping — *Operational*

- `pack_lists`: groups picked items into packages (carton/pallet), captures
  weight/dimensions per package.
- `shipments`: header linking one or more packages, carrier, tracking number, ship date,
  status (`pending` → `shipped` → `delivered`, delivered is manual/webhook-updated, not
  tracked live in v1).
- **Ship-confirm** is what triggers the actual Goods Issue (3E) — physically shipped is the
  real inventory-decrementing event, not the earlier pick/pack steps, which only move stock
  status, not ledger entries.

## 3Q. Cycle Counting — *Operational*

- `cycle_counts` / `cycle_count_lines`: scheduled count (by location, category, or ABC class —
  simple manual ABC flag on Product in v1, not computed), assigned counter, scan-to-count
  entry, system qty vs counted qty variance shown live.
- Completing a count with variances routes to Adjustment (3G) for review/approval before
  posting — counting itself never silently changes stock.
- Can optionally be scheduled via the **Schedule** module (recurring cycle-count task per
  location) once both modules are enabled for a tenant — Inventory doesn't require Schedule,
  but composes with it when present, same standalone-but-composable posture as every other
  Core module.

## 3R. Put-away Rules — *Operational*

- `putaway_rules`: warehouse, condition (by product category, or specific product), target
  zone/location, priority order (first-matching-rule wins).
- Applied automatically as the default destination on Goods Receipt lines (3D); always
  overridable manually at receipt time.

## 3S. Advanced & Optimization — **Future Version** (not built now, noted for schema forward-compatibility)

- **Wave/Zone/Cluster Picking**, **FEFO**, **Cross-docking**: layer on top of 3N–3R without
  altering the ledger — FEFO is a consumption-order variant of 3J scoped to batch expiry data
  already captured in 3L; wave/zone/cluster picking is a smarter `pick_lists` generation
  strategy, not a new data model.
- **Quality Management**: adds an inspection-hold status to `stock_ledger`/`stock_batches`
  (`quality_status`: pending/passed/failed) gating availability — placeholder column reserved
  in MVP schema (nullable, unused until built), same "additive migration only" discipline DMS
  applies to `extracted_text`.
- **Consignment**: adds an `ownership_type` (owned/consignment) + owning-partner reference
  (CRM `partner_id`) to `stock_valuation_layers` — doesn't change costing logic, just excludes
  consignment layers from the tenant's own balance-sheet valuation report.
- **Landed Cost**: an additive "cost adjustment" ledger entry type that redistributes
  freight/duty across a receipt's existing valuation layers after the fact.
- **Dock Scheduling**: a thin Inventory-specific `Resource` (dock door) registered against the
  **Schedule** module's existing Resource/Availability engine (`SCHEDULE_SPECS.md` §3D/3E) —
  Inventory should not build its own booking/availability logic when Schedule already owns it.
- **AI Forecasting / Slotting / Anomaly Detection / Predictive Replenishment / Analytics**:
  read-only analysis layers over `stock_ledger` history, natural extension of **AIInsights
  Core**'s per-tenant "ask your data" pattern — same ZDR requirement noted there applies here
  too before offering AI features to legal-vertical (or any) paying tenants.

---

# 4. Storage

**Database (schema `INVENTORY`, tenant DB):**

**Master / lookup tables**
- `INVENTORY.products`
- `INVENTORY.product_categories`
- `INVENTORY.product_barcodes`
- `INVENTORY.uoms`, `INVENTORY.uom_conversions`
- `INVENTORY.warehouses`
- `INVENTORY.locations` (self-referencing `parent_location_id`)
- `INVENTORY.location_barcodes`
- `INVENTORY.adjustment_reasons`
- `INVENTORY.putaway_rules` *(Operational)*

**Transaction / ledger tables**
- `INVENTORY.stock_ledger` — append-only, immutable, the single source of truth for every
  quantity change (`receipt` / `issue` / `transfer` / `adjustment`), references the originating
  document.
- `INVENTORY.stock_valuation_layers` — cost layers (FIFO) / running average snapshots, consumed
  per `INVENTORY.stock_ledger` issue-type entries.
- `INVENTORY.stock_balances` — denormalized current on-hand cache per
  product × warehouse × location (× batch/serial if tracked); rebuildable from `stock_ledger`.
- `INVENTORY.goods_receipts` / `INVENTORY.goods_receipt_lines`
- `INVENTORY.goods_issues` / `INVENTORY.goods_issue_lines`
- `INVENTORY.transfers` / `INVENTORY.transfer_lines`
- `INVENTORY.adjustments` / `INVENTORY.adjustment_lines`
- `INVENTORY.stock_batches` *(Operational)*
- `INVENTORY.stock_serials` *(Operational)*
- `INVENTORY.stock_reservations` *(Operational)*
- `INVENTORY.pick_lists` / `INVENTORY.pick_list_lines` *(Operational)*
- `INVENTORY.pack_lists` *(Operational)*
- `INVENTORY.shipments` *(Operational)*
- `INVENTORY.cycle_counts` / `INVENTORY.cycle_count_lines` *(Operational)*
- Custom metadata on `products` (and any entity that needs it) piggybacks on the existing
  `CUSTOMFIELDS` schema, same as every other Core module.

**Object File (per `CLAUDE.md` §7B, mirrors the existing per-tenant structure):**
```text
tenant_001/INVENTORY/
├── receipts/{receipt_id}/        # supplier invoice, packing list scans
├── shipments/{shipment_id}/      # BOL, carrier labels
└── counts/{count_id}/            # count sheets, photos of variance
```
- Actual file storage is handled by **DMS**, not duplicated here — Inventory documents attach
  via `DocumentService::attach()` with `subject_type = 'inventory.goods_receipts'` etc., same
  as every other module's DMS integration. This folder structure exists only as the
  `owning_module` partition DMS already reserves.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Modular monolith module at `app/Modules/Inventory/`, same shape as
every other Core module. No microservice extraction for MVP/Operational — this is
transactional CRUD + arithmetic (costing), not a different-runtime or heavy-async workload per
`CLAUDE.md` §2's extraction criteria. The only pieces that may eventually justify extraction,
per that same rule, are **AI Forecasting/Anomaly Detection** (Optimization tier — same
reasoning already applied to DMS's OCR and AIInsights) — not before then.

- **Internal facade** — `InventoryService::receive()`, `::issue()`, `::transfer()`,
  `::adjust()`, `::checkAvailability()`, `::reserve()` — preferred integration point for other
  modules, notably **Sales**'s Delivery Engine (issuing stock on ship-confirm, §5 above),
  **Purchase**'s Goods Receipt (receiving stock, §5 above), and any vertical module (e.g. Legal
  tracking evidence items).
- **Internal event bus** — `inventory.goods_received`, `inventory.goods_issued`,
  `inventory.stock_adjusted`, `inventory.low_stock`, `inventory.count_variance_found` — lets
  **WNE** route low-stock alerts / approval workflows without Inventory knowing anything about
  notification delivery, same seam as every other module.
- **Cross-schema FK, not duplication.** `goods_receipts`/`goods_issues` reference `CRM.partners`
  directly (Vertical/Core → Core is the allowed direction, and CRM is itself Core) for
  vendor/customer — Inventory does **not** build its own contact table, matching the "unified
  Partner registry" principle CRM was built to establish.
- **Cross-module reuse of WNE** for all alerting (low stock, expiring batch, count variance,
  adjustment approval) — no parallel notification code, same rule DMS and Schedule follow.
- **Cross-module reuse of Schedule** for Dock Scheduling (Future) and optional Cycle Count
  scheduling (Operational) — Inventory does not build its own resource/availability engine.
- **Cross-module reuse of DMS** for all document attachments (receipts, BOLs, QC certs) — no
  parallel file-storage code.
- **Cross-module reuse toward Purchase (soft dependency, receiving only).** Purchase's Goods
  Receipt (`PURCHASE_SPECS.md` §3E) calls `InventoryService::receive()` when Inventory is
  enabled for the tenant, to post the actual stock-ledger movement and valuation layer;
  Purchase's own `pur_receipt_hdrs`/`pur_receipt_lines` remain the authoritative procurement
  record (used for the three-way match, `PURCHASE_SPECS.md` §3F) regardless of whether
  Inventory is installed. Inventory itself has zero compile-time dependency on Purchase — it
  accepts a receipt from any caller (a vendor Partner via CRM, a Purchase PO, or a blank
  "opening balance") through the same `InventoryService::receive()` entry point.
- **Cross-module reuse toward Sales (soft dependency, fulfillment only).** Sales's Delivery
  Engine (`SALES_SPECS.md` §3H) calls `InventoryService::issue()` when Inventory is enabled
  for the tenant, to post the actual stock-ledger movement and valuation layer on ship-confirm;
  Sales's own `dlv_hdrs`/`dlv_lines` remain the authoritative order-fulfillment record
  (`so_lines.qty_delivered` derives from it) regardless of whether Inventory is installed.
  Inventory itself has zero compile-time dependency on Sales — it accepts an issue from any
  caller (a customer Partner via CRM, a Sales delivery, or an unlinked internal-consumption
  reason) through the same `InventoryService::issue()` entry point.
- **Cross-module reuse toward Accounting (GL posting only — not a costing dependency).**
  Inventory publishes `inventory.goods_received`, `inventory.goods_issued`,
  `inventory.stock_adjusted` — **Accounting** (`ACCOUNTING_SPECS.md` §3H) subscribes purely to
  post the corresponding GL journal (Inventory-asset ↔ COGS/GRNI/Adjustment), using the unit
  cost/value Inventory has already computed. Inventory remains the sole source of truth for
  both quantity (`stock_ledger`/`stock_balances`) and valuation
  (`stock_valuation_layers`/`CostingStrategyInterface`) — Accounting never recomputes or
  stores a competing figure. If Accounting is not installed/enabled for a tenant, Inventory
  functions fully on its own (no GL journals are posted, nothing else changes) — same
  optional-downstream-consumer posture every other module uses toward Accounting.

**Ledger-first integrity (the core design decision):** `stock_ledger` is append-only and is the
only source of truth for quantity; `stock_balances` is a cache, rebuildable at any time by
replaying the ledger. This mirrors DMS's audit-log philosophy (`access_logs` is append-only,
immutable) applied to a financial/quantity context instead of an access-trail context — a
single consistent pattern across the platform rather than two different ways of thinking about
"history that must never be edited."

**Costing strategy pattern:** `CostingStrategyInterface` with `FifoStrategy` / `AverageStrategy`
implementations, selected per tenant default (overridable per product) — new strategies
(Standard Cost, Landed-Cost-adjusted) are additive classes, no core engine change, same driver
pattern already established by WNE's `ChannelDriverInterface`, Schedule's
`ConferenceDriverInterface`, and DMS's `OcrDriverInterface`. Keeping this consistent across
modules is deliberate — it's what lets one solo dev (plus Claude Code) reason about the whole
codebase without relearning a new extension pattern per module.

**MVP scope boundary (be explicit about what's deferred):**
- `products.tracking_mode`, `quality_status` placeholder columns, and `ownership_type` exist
  structurally where cheap to reserve, but are unused/nullable until Batch/Serial (Operational)
  and Quality/Consignment (Advanced) are actually built — additive migrations only, no breaking
  change later, same discipline DMS applies to `extracted_text`/`pgvector`.
- No wave/zone picking optimization, no forecasting, no dock scheduling in MVP — all layer
  cleanly on top of the ledger + reservation/pick primitives once built.

**Barcode format agnostic:** no assumption about barcode symbology (UPC/EAN/Code128/QR) — the
`product_barcodes`/`location_barcodes` tables store the decoded string value only; scanning
hardware/camera decodes to text before it ever reaches the app layer, so no vendor lock-in.

**Queues:** Receipt/Issue/Transfer/Adjustment posting is synchronous (fast, user-facing, and
correctness-critical — costing must be computed in the same transaction as the ledger write to
avoid race conditions on concurrent postings against the same product). Only the
"publish `inventory.*` event → WNE picks it up" leg is async, reusing WNE's existing
`notifications` queue — no new queue needed for v1.

**Concurrency note:** costing calculations (especially FIFO layer consumption and Weighted
Average recalculation) must use row-level locking (`SELECT ... FOR UPDATE`) on the relevant
`stock_balances`/`stock_valuation_layers` rows during posting, to prevent two simultaneous
issues from double-consuming the same layer — flagged explicitly since this is the one place in
the module where a subtle concurrency bug would silently corrupt valuation.

**Suggested build order for Claude Code:** 3B/3C (product + location masters) → 3D/3E (receipt + issue, the minimum to have a working ledger) → 3H (stock card — cheap once the ledger exists,
high value for verifying correctness) → 3I/3J (valuation + costing engines) → 3F/3G (transfer +
adjustment) → 3K (barcode input wired into the above forms) — **MVP ships here** — then 3L/3M
(batch/serial) → 3N/3O (reservation + picking) → 3P (packing/shipping) → 3Q (cycle counting) →
3R (put-away rules) for Operational — then revisit 3S (Advanced/Optimization) only once real
usage volume justifies it.

**Marketability notes**
- Perpetual, ledger-based inventory (vs. a naive mutable-quantity field) is a genuine
  correctness/trust selling point for any regulated or audit-conscious buyer — worth surfacing
  explicitly, same way DMS's audit trail is a Legal-vertical selling point.
- Standalone-sellable Inventory (no Sales/Purchasing module required) opens a second go-to-market
  motion beyond the Legal vertical — small warehouses/retailers could be sold Inventory + DMS +
  Schedule as a bundle without ever touching the Legal module, which is a low-cost way to
  validate a second vertical direction per `CLAUDE.md` §5.
- Barcode support from day one is a floor-level expectation for any warehouse buyer — shipping
  MVP without it would undercut the sale; correctly prioritized as Core, not Operational.
- FIFO/Average selectable per tenant (not hardcoded) avoids losing deals to accounting-method
  mismatches — a cheap modeling decision now that prevents a real sales blocker later.
