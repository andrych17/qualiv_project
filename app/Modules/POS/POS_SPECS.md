# POS Module
## Core Shared Module — Configurable Point-of-Sale Transaction Engine (Retail/Convenience-Store + Restaurant profiles, Offline-first)

**Module category** (`CLAUDE.md` §2/§10): **Core**. POS has zero knowledge of any industry
vertical — it is a shared transaction engine any tenant can rent regardless of what they sell
(a legal firm's front-desk retainer top-up, a manufacturing tenant's factory-outlet counter, or
a dedicated Retail/F&B tenant with no other module installed at all). It is not Platform-level
(lives in the tenant DB, schema `POS`, like every other Core module) and no part of it meets
`CLAUDE.md` §2's microservice-extraction bar today — not even the offline client, which is a
browser-side PWA, not a server process. It is now listed in `CLAUDE.md` §4/§5/§7A (schema `POS`,
sequenced after HCM in the Core build order — see there for why) and wired into
`config/tenant_modules.php`'s `full` plan only — see §7 Open Items for what still needs a real
decision (a dedicated retail/F&B plan tier) rather than guessed at here.

**The one architectural decision everything else follows** (per the user's own brief, which this
spec adopts wholesale): **do not build separate Shop POS / Mini-Market POS / Restaurant POS
systems.** Build one transaction engine, and let a **POS Profile** — a config row, customization-
ladder rung 5 (`CLAUDE.md` §2) — turn capabilities on or off per terminal. **Restaurant** and
**Convenience Store** are therefore not Vertical modules in the `LEGAL`/future-`PROPERTY` sense;
they are **POS Profiles** living inside this one Core module (§3A), the same way Purchase's
three-way-match is one engine used identically by every tenant regardless of what they buy. A
profile is not just a capability checklist, though — Restaurant genuinely needs its own tables
(Floor/Table, Modifiers, KDS, §3M–§3O) that a pure retail counter never touches; those sections
are real schema, gated by the profile's capability flags, not a "someday" placeholder.

---

# 1. Backgrounds

> Pain point and business value.

- **Retail, mini-market, and restaurant point-of-sale look like three different products**, which
  tempts building three unrelated modules (or renting three different SaaS products). That
  triples the maintenance burden for a solo developer and, worse, throws away the ERP integration
  this platform already has — a separately-bought POS product needs its own product catalog sync,
  its own customer database, its own reporting, none of which agree with the ERP's own numbers.
  Every POS-specific concept that already has a Core-module answer (pricing → **Sales** price
  lists, stock → **Inventory**, customer identity → **CRM** partners, AR → **Sales → Accounting**,
  approvals/notifications → **WNE**, documents/receipts → **DMS**) should reuse that answer, not
  duplicate it — the same "don't own an existing Core module's job" discipline `MES_SPECS.md` and
  every other spec in this platform already applies.
- **Connectivity is not reliable at the point of sale.** A cashier or a waiter cannot tell a
  customer "the internet is down, please come back later" — a lost sale is lost revenue and a bad
  experience, and in Indonesian retail/F&B specifically (the platform's first market, per
  `CLAUDE.md` §1's Legal-first framing extending naturally to a future Retail/F&B tenant base),
  intermittent connectivity at a physical counter is the normal case, not the exception. **This is
  why Offline is treated as a Phase 1 requirement, not an Advanced/Future item** — every other
  module in this platform defers offline handling (`LEGAL_SPECS.md` §3M explicitly deferred its
  own offline sync queue, "no existing pattern in this codebase to build against yet") — POS is
  the module that finally builds that pattern, because it is the one module that cannot ship
  without it.
- **A physical register needs its own operational discipline** that no other module in this
  platform has needed yet: cash drawer reconciliation, shift open/close, terminal-level identity,
  hardware (scanner/printer/drawer/scale) — none of which map onto an existing Core module.
- **A basket transaction is not a Sales Order.** Sales's Quotation→Order→Delivery→Invoice pipeline
  assumes real elapsed time between steps and a receivable worth aging. A convenience-store basket
  is quoted, ordered, paid, and receipted in under a minute, by a walk-in customer who owes nothing
  once the drawer closes. Forcing every basket through the full Sales Order lifecycle would create
  hundreds of same-second, zero-balance "receivables" a day — noise Accounting's AR aging was never
  meant to carry. §3J works out the resulting posting boundary in detail; it is the single most
  important design decision in this spec, because it is where POS could easily violate
  `CLAUDE.md` §11's already-resolved "Sales is the sole AR-side caller" rule if handled carelessly.
- **Restaurant service is not a queue of baskets, it's a table with people at it.** A table opens,
  accumulates orders over an hour, is split or merged, and settles as a bill — table state, kitchen
  routing, and modifiers (per §13–§15 of the user's brief) are their own genuine operational
  problem, not a retail feature with a different skin.

**Client requirements** (condensed from the user's brief; the brief's full §1–§28 numbering is
kept as cross-reference commentary inside each subsection below rather than reproduced verbatim):
- One POS transaction engine + configurable capabilities (POS Profile, §3A) + Restaurant extension
  built as profile-gated sections of this same module, not a parallel product.
- **Offline-first is Phase 1, not a bolt-on** — a terminal keeps selling, taking payment, and
  printing receipts with zero connectivity, then syncs (§3S).
- Terminal/branch/register topology, cash session with open/close and variance reporting (§3B–3D).
- Product search fast enough for a real queue: barcode, PLU, touch grid, favorites (§3E), reusing
  Inventory's existing product/barcode/UoM master rather than a second catalog.
- Cart with hold/park/resume, item/basket discount, notes, customer assignment (§3F).
- Pricing and promotions reuse Sales's engine where the shapes match, extend where basket-level
  rules (BxGy, bundle, mix-and-match) don't exist there yet (§3G, §3H).
- Split/partial payment, cash change calculation, multiple tender types (§3I).
- Returns/refunds that automatically reverse the original inventory and financial effect (§3L).
- Restaurant: floor/table management, dining modes, modifiers, Kitchen Display System (§3M–§3O).
- Recipe-based ingredient consumption for restaurant/prepared-food items, via **PP**, not a
  second BOM engine (§3P).
- Hardware treated as pluggable adapters, never device-specific logic inlined into services
  (§3R).
- Granular permissions including in-transaction supervisor overrides distinct from menu-level
  trustee rights (§3U).

---

# 2. Goals

> Designated features solving the Backgrounds above, phased for a solo developer
> (`CLAUDE.md` §10 MVP bias — same phasing style as `INVENTORY_SPECS.md`/`MES_SPECS.md` §2).
> **Deliberate phasing choice**: Restaurant is not deferred to Phase 3 the way a brand-new
> capability normally would be — it is a headline ask in the user's brief and the sellable reason
> a tenant with a dine-in counter rents this module at all. What *is* deferred is everything that
> only matters once the core engine and its offline architecture are proven end-to-end on the
> simpler retail/convenience-store path — building Restaurant's table/KDS layer against an
> unproven cart/payment/offline core would mean re-doing both at once.

## Phase 1 — Core (ship first): the Retail/Convenience-Store path, end-to-end, offline-capable
- POS Profile & capability matrix (§3A) — even though only one profile (`retail`) ships its full
  UI in Phase 1, the config layer itself must exist first, since everything else reads it.
- Terminal / Branch / Register topology, POS Session (cash shift) with open/close, cash in/out,
  cash count and variance (§3B–3D).
- Product catalog & search consuming Inventory's product/barcode/UoM master, including case-pack
  barcode scanning and embedded-weight/price barcode parsing (§3E).
- Cart engine: add/remove/qty/UoM/price override, item and basket discount, hold/park/resume,
  customer assignment (§3F).
- Pricing via Sales's existing Price List engine (§3G) — no promotion engine yet in Phase 1
  (simple % / fixed discount only, same shape Sales's Quotation already supports).
- Payment engine: cash/card/QRIS/e-wallet, split payment, change calculation (§3I).
- AR/Revenue posting boundary (§3J) — the session-close summarized journal path for walk-in sales,
  and the named-customer on-account path via Sales — both **must** ship in Phase 1, since this is
  the decision that keeps POS from corrupting Accounting's AR guarantee from day one.
- Inventory posting: stock-out + automatic COGS on completed sale, with an explicit oversell
  policy toggle (§3K).
- Basic returns/refunds with automatic stock and financial reversal (§3L).
- **Offline architecture**: local catalog/price/customer cache, offline transaction capture,
  idempotent sync queue, conflict rules (§3S) — this is what makes the rest of Phase 1 real for a
  physical counter, not just a happy-path demo.
- Receipt printing (58/80mm) and basic hardware adapters: scanner, printer, cash drawer (§3R).
- Menu-level permissions (`menu.perm:POS_*`) and in-transaction supervisor PIN override for
  discount/void/refund (§3U).

## Phase 2 — Operational: Restaurant extension + Promotions + Loyalty
- **Restaurant extension**: Floor & Table management, dining modes (dine-in/takeaway/delivery),
  order lines with modifiers, split/merge bill, course/seat routing (§3M, §3N).
- **Kitchen Display System**: stations, order routing, NEW→PREPARING→READY→SERVED (§3O).
- **Recipe consumption**: POS sale of a prepared item explodes its PP recipe and consumes
  ingredients via Inventory, exactly the same boundary MES already established for production
  (§3P).
- **Promotion Engine**: Buy-X-Get-Y, bundle, mix-and-match, threshold, time-of-day (happy hour),
  customer-tier (§3H).
- **Loyalty / Membership**: points, tiers, redemption (§3T) — POS-owned, since neither CRM nor
  Sales has a loyalty concept today (checked against both specs; see §3T's boundary note).
- Gift card / store credit (§3U-adjacent, see §3T).
- Multi-branch price/tax/promotion variance, inter-branch reporting rollups.

## Phase 3 — Advanced (future version — do not build now)
- Omnichannel order aggregation (website/marketplace/WhatsApp → unified order queue).
- Self-checkout terminal mode, customer-facing display.
- QR table ordering (customer scans → digital menu → order feeds KDS directly).
- Weighing-scale hardware adapter beyond manual weight entry (Phase 1/2 accept a typed/scanned
  weight; a live serial/network scale integration is Advanced).
- AIInsight composition (demand-aware reorder suggestions, basket-affinity promotions) — same ZDR
  gate as every other AI feature (`CLAUDE.md` §5).
- Advanced hardware: card-terminal EMI/payment-gateway direct integration beyond a manual
  reference-number capture (Phase 1/2 record the payment and its reference; a live processor
  integration with webhook confirmation is Advanced).

---

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. POS Profile & Capability Matrix

**Function / Features**
- `pos_profiles`: code, name, base type (`retail` | `restaurant` | `service` — the three top-level
  operating models from the user's brief §1/§27; `service` — appointment/repair — is named here
  for schema forward-compatibility only, not built until a real use case exists, see §7), and a
  capability flag set: `requires_barcode`, `touch_menu`, `multi_uom`, `batch_expiry_tracking`,
  `weight_scale`, `customer_required`, `loyalty_enabled`, `promotion_enabled`, `table_management`,
  `modifiers_enabled`, `kds_enabled`, `recipe_consumption`, `delivery_enabled`, `offline_enabled`
  (defaults **on** for every profile — see Backgrounds), `multi_branch`.
- `pos_terminals.profile_id` assigns a profile per terminal — **not per tenant**, so one branch can
  run a convenience-store front counter and a small dine-in corner on two different terminals
  without needing two tenants or two modules.
- The Vue POS shell reads the active terminal's resolved capability set once at session-open and
  conditionally mounts UI sections (Table view, Modifier picker, KDS ticket panel) — same
  "read config, don't branch on tenant identity" principle as the customization ladder
  (`CLAUDE.md` §2), applied at the profile layer instead of the tenant layer.

**Rules / Logic**
- Two seeded profiles ship with the module: `Convenience Store` (barcode-first, no table/modifier/
  KDS) and `Restaurant` (touch-menu-first, table/modifier/KDS on) — matching the user's brief §28
  capability matrix exactly. Tenants may clone and edit either rather than being locked to the
  defaults (customization-ladder rung 3/4: capability flags are simple booleans today; if a tenant
  needs a genuinely new capability axis later, that is a new flag on this table, never a
  tenant-branch in application code).
- A capability flag gates **UI visibility and validation**, not data storage — e.g. a `retail`
  terminal's `pos_txn_hdrs` row still has a (unused) `table_id` column; disabling
  `table_management` just means the UI never shows a table picker and the Store layer never
  requires one. This keeps §3M–§3O's schema shared instead of forking into a parallel
  restaurant-only transaction table.

## 3B. POS Terminal / Branch / Register Topology

**Function / Features**
- `pos_terminals`: branch_id (references a Branch — `SYSCONFIG` or a future dedicated Branch
  master if one exists; until then a simple `pos_branches` lookup, since no other Core module
  currently owns a formal multi-branch concept beyond Inventory's warehouses — see §5 note),
  warehouse_id (**Inventory** reference — which warehouse this terminal's sales issue stock
  from), profile_id (§3A), code, name, default price_list_id (**Sales** reference, §3G),
  default tax configuration, receipt template reference, device fingerprint (for offline client
  identity, §3S), is_active.
- Terminal-specific overrides: price list, tax, payment methods enabled, receipt template — each
  an optional override column on `pos_terminals`, falling back to the branch/tenant default,
  same override-cascade pattern Sales's Price List resolution already uses (§3G).
- Hardware configuration (§3R) is attached per terminal, not per tenant — one branch can have a
  58mm-printer terminal and an 80mm-printer terminal side by side.

**Rules / Logic**
- A terminal cannot be deleted while it has an open `pos_sessions` row (§3C) or unsynced offline
  transactions (§3S) — same "can't delete what's in use" integrity rule Inventory applies to
  Locations (`INVENTORY_SPECS.md` §3C).

## 3C. POS Session (Cash Shift)

**Function / Features**
- `pos_sessions`: terminal_id, cashier (`HCM.employees` or `SYSCONFIG` user reference — whichever
  identity the tenant's staff actually log in as; see §5), opened_at, opening_cash, status
  (`open` → `closed`), closed_at, expected_cash (computed at close — opening_cash + cash sales +
  cash-in − cash-out − cash refunds, per the user's brief §11 worked example), actual_cash
  (entered by cashier at close), variance (computed), closed_by, `approved_by` (nullable — set
  when variance exceeds a configurable threshold and requires supervisor sign-off, §3U).
- **This is distinct from `HCM.shifts`/`HCM.shift_assignments`** (the employee work-roster
  concept MES reads read-only, `MES_SPECS.md` §3P) — a POS Session is a cash-drawer session tied
  to a terminal, not a work schedule; a cashier can open/close a POS Session independent of
  whatever HR shift they're rostered on. No FK to HCM's shift tables in Phase 1; a reporting-only
  cross-reference is a Phase 2 nicety, not a dependency.
- One open session per terminal at a time — starting a new session requires closing (or a
  supervisor force-closing) any prior open session first.

**Rules / Logic**
- `pos_cash_movements` (session_id, type `cash_in`/`cash_out`/`petty_cash`, amount, reason,
  user_id, occurred_at) — every non-sale cash movement is its own row, never a mutation of
  `opening_cash`, same append-only discipline `MES.mes_prod_events`/`INVENTORY.stock_ledger`
  already apply to their own domains.
- Session close is also the trigger point for §3J's summarized revenue/tax journal posting — a
  session cannot close while it has unsynced offline transactions (§3S) still pending, so the
  posted total is always complete, never a partial snapshot silently reconciled later.
- A cash variance beyond a tenant-configured threshold (`SYSCONFIG.config_consts`, customization-
  ladder rung 1) blocks close until a supervisor PIN override is captured (§3U) — same
  approve-then-proceed pattern as Inventory's large-adjustment approval
  (`INVENTORY_SPECS.md` §3G).

## 3D. Cash Management — Reporting

**Function / Features**
- Per-session cashier report (opening/sales/refund/cash-in/cash-out/expected/actual/variance),
  matching the user's brief §11 and §24 "Cashier" report exactly — a read model over §3C's
  session row plus its `pos_cash_movements` and completed `pos_txn_hdrs` for that session, no
  separate storage.
- Multi-session, multi-terminal roll-up for a branch/day — same read-model posture.

## 3E. Product Catalog & Search

**Function / Features**
- POS consumes **Inventory's** existing Product Master (`INVENTORY_SPECS.md` §3B) directly — no
  second product table. Barcode (including case-pack, `product_barcodes` with unit multiplier —
  already solves the user's brief §3 "1 Carton = 24 Bottles" example exactly as specced), UoM
  conversion, batch/serial/expiry, and category all reuse Inventory's existing schema and
  `INVENTORY_SPECS.md` §3K Barcode Engine unmodified.
- **The one thing POS genuinely adds**: parsing embedded-weight/embedded-price EAN-13 barcodes
  (common on scale-labeled deli/produce items — prefix digit range configurable per tenant,
  remaining digits split into item code + weight or price per a configurable template). This is
  POS-owned (`pos_weighted_barcode_templates`: prefix range, item-code digit span, value digit
  span, value type `weight`/`price`, decimal places) since Inventory's own barcode table has no
  reason to know about this retail-specific encoding.
- Search: barcode/weighted-barcode scan (text-field input, same "works with any HID scanner, no
  special hardware dependency" posture as `INVENTORY_SPECS.md` §3K), SKU, name, PLU code
  (`product_barcodes.type = 'plu'`, reusing the same table rather than a new one), touch-grid
  category browse (Restaurant/touch profiles), favorites and recently-sold (per-terminal or
  per-cashier, `pos_favorite_items`).
- All of this must work fully **offline** against the locally cached catalog (§3S) — search is
  never a network call from the cashier's perspective.

**Rules / Logic**
- A product not in Inventory's master cannot be sold at POS except as an "Open Item" (free-text
  description + manual price, `pos_txn_lines.is_open_item = true`, no `product_id`) — for the rare
  miscellaneous sale a tenant doesn't want to formally master-data every SKU for; open-item lines
  never post a stock movement (§3K), only revenue.

## 3F. Cart Engine

**Function / Features**
- Add/remove line, change qty/UoM (converted via Inventory's existing UoM factors), line-level
  price override (permission-gated, §3U) and discount (%, fixed), basket-level discount, notes
  (line and basket), customer assignment (walk-in default, §3G... see §3I Customer Engine),
  rounding rule (tenant-configured, e.g. round to nearest 100/500 for cash-heavy markets).
- **Hold / Park / Resume**: `pos_txn_hdrs.status = 'parked'` — a parked cart is not a draft the
  cashier is still editing, it's a suspended sale set aside to serve another customer, per the
  user's brief §6 worked example. Multiple parked carts per terminal, listed by park time/label
  (e.g. "Table 4" in a service context, a customer name, or just a sequence number).
- Cart state lives client-side first (§3S) and is only durable server-side once synced — this
  means Hold/Park must work fully offline too, since a busy queue is exactly when connectivity is
  most likely to be strained.

**Rules / Logic**
- A parked cart auto-expires (configurable, e.g. 4 hours) into a cancelled state to avoid
  indefinitely "stuck" stock/price locks — informational only in Phase 1 (no reservation is
  actually held against Inventory for a parked cart; stock is only touched at §3K completion),
  since holding a live reservation per parked basket would be a real Inventory load for a feature
  that's supposed to be lightweight.

## 3G. Pricing Engine (Sales integration, boundary note)

**Function / Features**
- POS **does not own a pricing table**. Price resolution reuses Sales's existing engine exactly as
  documented (`SALES_SPECS.md` §3B/§3G): terminal's assigned Price List (§3B) → customer's
  assigned Price List (if a named customer is on the basket, §3I) → line override (permission-
  gated). No POS-specific price list concept — a "POS price" the user's brief §7 lists as a
  possible price tier is just another `SALES.price_lists` row scoped to a POS terminal/branch, the
  same way a Wholesale or Member price list already is.
- Price Lists (and their lines) are part of the offline catalog cache (§3S) — a terminal cannot
  resolve pricing offline against a price list it hasn't already synced.

**Rules / Logic**
- Same "promotions are additive to price-list pricing, never edit the price list itself"
  discipline `SALES_SPECS.md` §3G already states — POS's own Promotion Engine (§3H) inherits this
  rule rather than restating a different one.

## 3H. Promotion Engine (Phase 2)

**Function / Features**
- **Why POS owns this rather than reusing `SALES.promo_codes`**: Sales's promotion shape (a single
  code, % or fixed, applied at Quotation/Order entry, `SALES_SPECS.md` §3G) fits a sales-order
  workflow with a human typing a code in. A POS basket needs real-time, code-free, line-and-basket
  *rule evaluation* — Buy-X-Get-Y, bundle pricing, mix-and-match tiers, spend thresholds, time-of-
  day windows, customer-tier rates (user's brief §8) — evaluated automatically as items are
  scanned, and it must evaluate **offline**, against the locally cached rule set, with zero live
  calls. That is a different engine shape, not a smaller version of the same one; forcing POS to
  call into Sales for every scanned item would also reintroduce the exact server round-trip
  offline-first is meant to eliminate. `SALES.promo_codes` remains untouched and still works
  exactly as before for Sales's own Quotation/Order flow; nothing here changes it.
- `pos_promotion_rules`: name, type (`simple_discount` / `buy_x_get_y` / `bundle` /
  `mix_and_match` / `threshold` / `time_window` / `customer_tier`), scope (product/category/
  basket-wide), value (% or fixed or bundle price), constraints (jsonb — qty thresholds, valid
  product set, valid time window, valid customer tier/segment), valid_from/valid_to, priority
  (evaluation order when multiple rules could apply), stackable (bool).
- Evaluated client-side against the cached rule set (§3S) every time the cart changes — same
  "search is never a network call" posture as §3E.
- A tenant that also uses Sales's `promo_codes` for its e-commerce/quote channel can still honor
  those same codes at POS as a manual code-entry fallback (`pos_promotion_rules.type =
  'promo_code_passthrough'`, reads the code's discount shape from `SALES.promo_codes` at sync
  time, cached locally) — this is the one place the two engines meet, and only as a read, never a
  shared table.

**Rules / Logic**
- Only one `simple_discount`/`bundle`/`mix_and_match` rule applies per line unless explicitly
  marked `stackable` — prevents runaway compounding discounts, same "explicit, not implicit"
  posture the user's brief itself calls out as important for cashier-trust in the discounted
  total.

## 3I. Payment Engine & Customer Identification

**Payment Engine**

**Function / Features**
- `pos_payments`: txn_id, method (`cash` / `card` / `qris` / `bank_transfer` / `e_wallet` /
  `voucher` / `gift_card` / `store_credit` / `customer_credit` / `on_account`), amount, reference
  (auth/approval code, free text — Phase 1/2 do not integrate a live payment gateway, per §2
  Phase 3), change_given (cash only).
- **Split payment**: multiple `pos_payments` rows per `pos_txn_hdrs`, sum must equal
  `grand_total` before the sale can complete — matches the user's brief §10 worked example
  exactly.
- **Partial payment**: allowed only when `on_account`/`customer_credit` is one of the tenders and
  a named customer (§3I Customer Engine below, not to be confused with this section) is on the
  basket — a partial payment against a walk-in customer makes no sense (nobody to collect the
  balance from later), so the UI blocks it unless a customer is assigned.
- Customer Display (hardware, §3R) mirrors the running total and tender breakdown live.

**Rules / Logic**
- Gift card and store credit redemption (§3T) are **double-spend hazards across terminals** when
  offline — see §3S's explicit rule for what a terminal does when it cannot verify a balance
  live.

**Customer Identification**

**Function / Features**
- Walk-in by default (`pos_txn_hdrs.customer_id = null`, display label "Walk-in"). Optional
  identification against **CRM's** `partners` table (`CRM_SPECS.md` §3B) — search by
  phone/name/loyalty-card scan, or quick-create (name + phone minimum, same lightweight-create
  pattern CRM's own Quick Contact uses).
- Customer assignment on a basket unlocks: assigned Price List (§3G), Loyalty accrual/redemption
  (§3T), on-account/partial payment (§3I), purchase history (a read view over the customer's past
  `pos_txn_hdrs`, same "one store, many views" pattern `SALES_SPECS.md` §3B already documents for
  its own Customer view over `CRM.partners`).
- **POS does not create a `customer_sales_profile`** (that stays Sales-owned per `SALES_SPECS.md`
  §3B) — POS reads a customer's assigned price list from Sales's existing profile if the tenant
  has Sales installed, and falls back to the terminal's default price list otherwise, same
  optional-module posture Sales already applies to Inventory (`SALES_SPECS.md` §3H).

**Rules / Logic**
- Customer identification is optional for `retail`/`convenience` profiles (capability flag
  `customer_required = false` by default, §3A) and can be made mandatory per profile for tenants
  that want guaranteed loyalty capture (e.g. a membership-driven mini-market).

## 3J. AR / Revenue Posting Boundary — the critical design decision

> **This section is the reason `CLAUDE.md` §11's "Sales is the sole AR-side caller" resolution
> does not get quietly broken by this module.** Read this before touching `pos_txn_hdrs` or
> `pos_sessions` code.

**The problem**: a naive design routes every completed basket through
`SalesOrderService::createFromExternalRequest(...)` (`SALES_SPECS.md` §3I, the same entry point
Legal uses, `LEGAL_SPECS.md` §2) the way every other module's billable event does. At real
convenience-store volume (hundreds of baskets/day, fully paid at the counter in the same
transaction they're created in) that manufactures hundreds of same-second Sales Orders → AR
Invoices → immediately-applied Payments a day — each one opening and closing an AR balance that
never actually existed as a receivable. It pollutes Accounting's AR aging with noise, and turns a
Phase-1 offline sync replay into a bulk Sales-Order-creation problem instead of a bulk
journal-posting problem.

**The resolution — two distinct posting paths, chosen by whether a real receivable exists:**

- **Walk-in / fully-paid-at-sale (the default case — cash, card, QRIS, e-wallet, gift card, store
  credit, any tender fully covering the total in the same transaction)**: **no AR is created at
  all.** `pos_txn_hdrs`/`pos_txn_lines`/`pos_payments` (§3F/§3I) are POS's own system of record for
  the sale — nothing else needs to duplicate them. At **POS Session close** (§3C), POS calls
  `AccountingService::postJournal(...)` / fires `JournalPostingRequested`
  (`ACCOUNTING_SPECS.md` §3R — the generic "any module can post financial transactions" entry
  point, explicitly **not** restricted to Sales the way `InvoiceRequested` is) **once per session**,
  with a summarized posting bucketed by payment method and tax code: Dr Cash/Bank (per tender
  type) and Sales Discount, Cr Sales Revenue and Tax Payable, `subject_type = 'pos.pos_sessions'`,
  `subject_id` = the session id. This never touches the AR control account, because there is
  nothing receivable about a transaction that was paid in full when it happened — Sales's
  AR-orchestration role exists to answer "who owes us and how much," which is moot here, so this
  is not an exception to `CLAUDE.md` §11's rule, it's a case the rule was never meant to cover.
  COGS posts automatically and separately, the same way it already does for every other module —
  §3K's `InventoryService::issue()` call fires `inventory.goods_issued`, which
  `InventoryGlPostingService` (`ACCOUNTING_SPECS.md` §3R, already built for Sales's own Delivery
  Engine) already listens for and posts, with zero POS-specific code required.
- **Named-customer, on-account / partial payment (a real receivable — the customer owes a
  balance after leaving)**: this genuinely is a receivable, so it goes through the ordinary path
  every other module uses — `SalesOrderService::createFromExternalRequest(...)`,
  `subject_type = 'pos.pos_txn_hdrs'`, `subject_id` = the transaction id, customer =
  `CRM.partners` reference. Sales creates and confirms a Sales Order and fires `InvoiceRequested`
  for the unpaid balance per its own Billing Engine (`SALES_SPECS.md` §3I); any tender already
  captured at POS (the paid portion of a partial payment) rides along as an immediate
  `PaymentRequested` against that invoice. POS still owns `pos_txn_hdrs` as its own record of the
  basket; the Sales Order is the AR-side shadow of it, linked by the `subject_type`/`subject_id`
  pointer, same one-way relationship Legal's matters have to their own Sales Orders.
- **Refunds/returns (§3L)** follow the mirror of whichever path the original sale took: a
  walk-in refund posts a reversing summarized journal at the next session close (or immediately,
  tenant-configurable, if same-day cash refunds need to hit the till total right away); an
  on-account refund requests an `ArCreditNote` from Accounting exactly as `SALES_SPECS.md` §3J
  already documents for Sales's own Returns Engine.

**Rules / Logic**
- A `pos_txn_hdrs` row is never itself an AR-relevant document — Accounting never queries or
  references POS's schema directly, same one-way `Core → Accounting` dependency rule
  `ACCOUNTING_SPECS.md` states for every other caller.
- If Accounting is not installed for a tenant, POS still functions (sale, payment, receipt,
  inventory posting) — the session-close journal call and the on-account Sales Order path are both
  simply skipped, same optional-module posture `SALES_SPECS.md` §3I already documents for its own
  Billing Engine when Accounting isn't installed.
- If Sales is not installed but Accounting is, the on-account path has no entry point (Sales owns
  it exclusively) — on-account/partial payment is disabled in the UI for that tenant; walk-in
  fully-paid sales are unaffected, since the session-close journal path doesn't depend on Sales at
  all.

## 3K. Inventory Posting

**Function / Features**
- On sale completion, POS calls `InventoryService::issue(...)` (`INVENTORY_SPECS.md` §3E) per
  line, `subject_type = 'pos.pos_txn_lines'`, from the terminal's assigned warehouse (§3B) —
  Inventory creates its own `goods_issues`/`stock_ledger` entry and consumes valuation layers per
  its costing method, exactly the pattern already established for Sales's Delivery Engine
  (`SALES_SPECS.md` §3H) and MES's Material Consumption (`MES_SPECS.md` §3J). POS does not write
  to `INVENTORY.stock_ledger` directly, and does not implement its own costing logic.
- Open-item lines (§3E) and service-type products never call `InventoryService` — no stock effect.
- Batch/serial/expiry-tracked products (`INVENTORY.stock_batches`/`stock_serials`) resolve at
  scan time from the cached catalog (§3S); the specific batch/serial consumed is recorded on
  `pos_txn_lines` exactly like any other Inventory-integrated module.

**Rules / Logic**
- **Oversell policy (the offline-critical rule)**: Inventory's Goods Issue normally *blocks*
  posting when requested quantity exceeds available (`INVENTORY_SPECS.md` §3E) — a register
  cannot apply that block the same way. A live terminal reading `checkAvailability()` as
  informational-only (warns, never blocks — same posture MES's own component-availability strip
  already uses, `MES_SPECS.md` §3G) is fine online; but an **offline sale is posted after the
  fact**, possibly against stock that has since been depleted by other terminals' own offline
  sales queued for the same sync window. POS therefore introduces a tenant-level
  `SYSCONFIG.config_consts` toggle, `POS_ALLOW_OVERSELL` (customization-ladder rung 1, default
  **on**): when on, `InventoryService::issue()` is called with an explicit `allow_negative =
  true` override flag POS passes through (a new, narrow parameter on the existing call — not a
  behavior change to any other caller), and a resulting negative on-hand balance is flagged on
  the Dashboard (§3V) as a variance to investigate, never silently hidden. When off, an offline
  sale that would oversell still completes at POS (a register cannot refuse a sale mid-queue) but
  the resulting Inventory posting is queued to a manual-review exception list instead of posting
  automatically — the tenant's choice between "always let the sale happen, reconcile stock after"
  and "never silently oversell, review before it hits the ledger."

## 3L. Returns / Refunds

**Function / Features**
- `pos_return_hdrs`/`pos_return_lines`: original `txn_id` reference, reason code, line-level
  qty/condition, refund method (same tender types as Payment, §3I, plus `store_credit`/
  `voucher` as refund-only destinations), status (`requested` → `approved` → `completed`).
- **Automatic reversal** (per the user's brief §12): completing a return calls
  `InventoryService::receive(...)` for restockable lines (reversing §3K's issue) and follows
  §3J's mirrored posting path for the financial reversal — never a manual two-step "adjust stock,
  then separately adjust the books."
- Return-without-original-receipt: allowed if tenant-configured, requires manager PIN (§3U),
  price defaults to current price list rather than an unknown original price.

**Rules / Logic**
- A return above a configurable value threshold routes through **WNE**
  (`workflow_code = pos.return_approval`) for manager approval before completing — same optional-
  approval pattern `SALES_SPECS.md` §3J already uses for its own Returns Engine.

## 3M. Restaurant Extension — Floor & Table Management (Phase 2, `table_management` capability)

**Function / Features**
- `pos_floors` (name, layout reference) → `pos_tables` (floor_id, code/label, seat count, position
  x/y for a visual floor-plan view, status: `available` / `occupied` / `reserved` / `cleaning`).
- `pos_txn_hdrs.table_id` (nullable — only meaningful when `table_management` is on, §3A) links a
  transaction to a table; a table can carry multiple open transactions across its dining lifetime
  until settled (e.g. drinks ordered separately from mains, still one bill at close).
- Operations: open table (creates the table's active transaction), assign waiter, move table
  (reassign `table_id`), merge tables (combine two active transactions' lines into one, void the
  other), split table (inverse — move a subset of lines to a new transaction), transfer, split
  bill (divide one transaction's total across N payment sets without splitting the lines
  themselves — a payment-allocation view, not a line-level split), merge bill (the reverse).
- Visual floor-plan view: `pos_tables` rendered at their x/y position with a `StatusBadge`-colored
  state, same shared-component discipline as every other view (`CLAUDE.md` §9D6).

**Rules / Logic**
- A table cannot be marked `available` while it has an open (non-`completed`/`cancelled`)
  transaction — the same "can't delete/free what's in use" integrity rule already applied to
  Locations (`INVENTORY_SPECS.md` §3C) and this module's own Terminals (§3B).

## 3N. Restaurant Extension — Order Lines & Modifiers (Phase 2, `modifiers_enabled` capability)

**Function / Features**
- `pos_modifier_groups` (name, selection type `single`/`multiple`, min/max selections) →
  `pos_modifiers` (group_id, name, price delta — additive or the group's item can be flagged
  `replaces_base_price` for a size-tier style group). Attached to a product via
  `pos_product_modifier_groups` (product_id, group_id) — reuses Inventory's `product_id`, no
  second product concept.
- `pos_txn_line_modifiers` (txn_line_id, modifier_id, price delta captured at time of sale — never
  re-resolved from current modifier price after the fact, same "resolved value survives a later
  master-data edit" discipline `MES_SPECS.md` §3B already applies to BOM/Recipe resolution).
- Special instruction (free text, e.g. "no onion"), kitchen note, course (`appetizer`/`main`/
  `dessert`/etc., tenant-editable lookup), seat number — all on `pos_txn_lines`.

**Rules / Logic**
- A modifier group's min/max selection constraint is enforced client-side at add-to-cart time
  (offline-capable, same posture as §3H's promotion evaluation), not just server-side on sync.

## 3O. Kitchen Display System (Phase 2, `kds_enabled` capability)

**Function / Features**
- `pos_kds_stations` (name, e.g. Kitchen/Bar/Dessert — a printer-routing and screen-routing
  target). `pos_txn_lines.kds_station_id` resolves from the product's configured station
  (`pos_product_kds_routing`), so a single order fans out to the right station(s) automatically.
- Ticket status per line: `new` → `preparing` → `ready` → `served`, each a timestamped write —
  same event-style discipline as `MES.mes_prod_events` (`MES_SPECS.md` §3C), though scoped small
  enough here not to need a separate ledger table; the status + timestamp columns on
  `pos_txn_lines`/a lightweight `pos_kds_ticket_events` table are sufficient at this volume.
- KDS screen: real-time (polling or broadcast, per §5) ticket queue per station, priority, elapsed
  time, cancelled-item flag, re-fire action (re-opens a `served`/`ready` line back to `new` with an
  audit note — never a silent duplicate).
- Kitchen printer routing as one more hardware adapter (§3R) alongside the receipt printer — a
  ticket can print, display on a KDS screen, or both, per tenant hardware.

**Rules / Logic**
- KDS requires connectivity to be useful across multiple screens/printers in real time — when the
  terminal is offline (§3S), KDS routing degrades to local ticket printing only at that terminal's
  own attached kitchen printer (no cross-station sync possible without a network), clearly
  surfaced to the cashier/waiter rather than silently failing.

## 3P. Recipe Consumption — Owned by PP/MES boundary (common to both profiles)

**Function / Features**
- A prepared-food product (a restaurant dish, or a retail deli/bakery item made in-house) resolves
  its active recipe via `PpService::getActiveRecipe(productId)` (`PP_SPECS.md` §3D, the same
  contract `MES_SPECS.md` §3B already consumes) at the moment of sale, explodes it to ingredient
  quantities, and issues each ingredient via `InventoryService::issue()` — POS does not own a
  recipe/BOM table of its own, exactly the same "don't duplicate an existing Core module" boundary
  MES already established for its own material composition.
- This is an **optional-module posture**: if PP is not installed, the product simply sells as a
  single finished-goods line with its own direct stock issue (§3K) and no ingredient explosion —
  same graceful-degradation pattern Sales already applies when Inventory isn't installed
  (`SALES_SPECS.md` §3H).

**Rules / Logic**
- Ingredient consumption from a POS sale is **not** routed through MES's production-order
  machinery (`mes_prod_order_hdrs`) — there is no shop-floor execution step for a bar order pouring
  a drink; it is a direct recipe-explosion-to-issue, the lightweight sibling of MES's own
  batch/process execution for a context that doesn't need work orders, routing, or QC gates.

## 3Q. Hardware Integration

**Function / Features**
- `POSHardwareAdapter` interface (mirrors MES's `IotProtocolAdapter` pluggable-adapter pattern,
  `MES_SPECS.md` §3S) — concrete adapters for: receipt printer (58mm/80mm/A4, ESC/POS over
  USB/network), kitchen printer (§3O), cash drawer (opened via printer kick-pulse or direct USB),
  barcode scanner (HID text-input, no adapter needed — already covered by §3E), customer display
  (pole display or a second browser tab/window mirroring the running total), weighing scale
  (Phase 1/2: manual/typed weight entry only; a live scale adapter is Phase 3, §2), card/payment
  terminal (Phase 1/2: manual reference-number capture only; a live gateway adapter is Phase 3).
- Hardware config lives per terminal (§3B) — `pos_terminal_devices` (terminal_id, device_type,
  adapter_code, connection config jsonb).

**Rules / Logic**
- Never inline device-specific protocol logic into `POS` services — same discipline
  `MES_SPECS.md` §3S already states for its own protocol adapters; a hardware failure (printer
  offline, drawer jammed) degrades gracefully (queue the print job, surface a clear on-screen
  retry) and never blocks the underlying sale from completing.

## 3R. Loyalty / Membership & Gift Card / Store Credit (Phase 2/3)

**Function / Features**
- **Why POS owns this**: checked against both `CRM_SPECS.md` (Contacts/Companies/Leads/After
  Sales/Helpdesk — no loyalty concept) and `SALES_SPECS.md` (Price Lists/Credit — no points/tier
  concept either) — neither Core module has claimed this territory, and it is fundamentally a
  transaction-volume-driven concern (points accrue per basket), so POS is the natural owner. If a
  future need arises for loyalty to be usable outside POS (e.g. Sales's own e-commerce channel
  accruing the same points), promoting this to its own Core concern is a real option then — not
  guessed at here, see §7.
- `pos_loyalty_tiers` (name, points-per-currency-unit rate, tier threshold) →
  `pos_loyalty_accounts` (`customer_id` — CRM.partners reference, current tier, points balance) →
  `pos_loyalty_ledger` (account_id, txn_id nullable, type `earn`/`redeem`/`expire`/`adjust`,
  points delta, occurred_at) — append-only, same discipline as every other ledger in this
  platform.
- `pos_gift_cards` (code, balance, currency, expiry, status `active`/`redeemed`/`expired`) and
  `pos_store_credits` (customer_id, balance, source reference e.g. a return, §3L) — both usable as
  a Payment Engine tender type (§3I).

**Rules / Logic**
- **Double-spend across terminals when offline**: a gift card/store-credit balance cached locally
  can be stale the instant a second terminal redeems from the same card while offline. Default
  policy (`SYSCONFIG.config_consts`, tenant-configurable): gift-card/store-credit/loyalty-point
  redemption **requires connectivity** (blocked at the terminal with a clear message if offline —
  "Gift card redemption needs a connection — try cash/card, or reconnect and retry," per
  `DESIGN.md` voice guidance) while gift-card/loyalty **accrual** (issuing a new card, earning
  points on a sale) is always offline-safe, since accrual can't be double-spent, only redemption
  can. A tenant that accepts the small fraud risk may flip redemption to offline-allowed; the
  resulting balance conflict is then a Phase-2 reconciliation report (flag any account whose
  ledger sum went negative after sync), never silently absorbed.

## 3S. Offline Architecture (Phase 1 — the module's defining capability)

> **The codebase's first real offline-first / PWA pattern.** `LEGAL_SPECS.md` §3M explicitly
> deferred its own field-visit offline sync queue, "no existing pattern in this codebase to build
> against yet" — this section is that pattern, built for real because POS cannot ship without it.
> Whatever lands here should be written so Legal's own deferred item (and any future module that
> needs offline tolerance) can build on it rather than invent a third approach.

**Function / Features**
- **Client**: a PWA (installable, service-worker-backed) built on the existing Vue 3/Inertia
  frontend for the POS shell specifically — not the whole admin app. Per `CLAUDE.md` §2's Web vs
  future clients boundary ("Ship REST only when a non-Inertia client is real, not speculative"),
  offline POS **is** that real, non-speculative case (the same bar `LEGAL_SPECS.md` §3M already
  cleared for its own mobile field-visit surface) — so the POS shell is served over a thin,
  versioned `api/v1/pos/*` REST surface instead of Inertia's request/response cycle, since Inertia
  has no offline story at all. Reuses the exact auth pattern already built for Legal's mobile API:
  Sanctum bearer token + `X-Tenant-Id` header, `InitializeTenancyByHeader` middleware
  (`LEGAL_SPECS.md` §3M), no new auth mechanism invented.
- **Local storage**: IndexedDB (via a thin wrapper, e.g. Dexie.js — a real dependency addition,
  justified because hand-rolling IndexedDB transaction logic for this volume of synced data is
  exactly the kind of wheel `CLAUDE.md`'s Ponytail coding discipline says not to reinvent) holding:
  cached product catalog + barcodes + UoM (§3E), active price lists (§3G), active promotion rules
  (§3H), customer lookup subset (§3I, e.g. recently-transacted + loyalty-card-indexed, not the
  tenant's entire customer base), open POS Session state (§3C), and the **outbound sync queue**
  (every offline-created/modified `pos_txn_hdrs`/`pos_payments`/`pos_return_hdrs`/
  `pos_cash_movements` row).
- **Sync**: on reconnect, the client posts its queue to `api/v1/pos/sync` in client-generated-
  order batches; the server applies each queued mutation, returns per-item success/conflict, and
  the client clears synced items / surfaces conflicts for the ones that failed.
- **Cache refresh**: catalog/price/promotion/customer caches refresh opportunistically whenever
  online (background, non-blocking) and on session-open — a terminal that's been offline for days
  sells against a stale cache rather than not selling at all, which is the entire point.
- **Storage persistence**: the client calls `navigator.storage.persist()` at checkin/session-open
  to request the browser's "persistent" storage bucket, so the IndexedDB cache and outbound sync
  queue aren't silently evicted under disk pressure the way "best-effort" storage can be — a POS
  terminal cannot afford to lose queued, unsynced transactions to browser storage cleanup.

**Rules / Logic — idempotency is the load-bearing rule of this whole section**
- Every offline-created transaction carries a **client-generated UUID**
  (`pos_txn_hdrs.client_txn_uuid`, unique, generated at cart-creation time, not at sync time) —
  sync is idempotent on this key: a retried/duplicated sync request for the same UUID is a no-op
  on the second and later attempts, never a double-post of stock or revenue. This is the single
  most important correctness rule in this section; every write path this section touches (§3J's
  journal posting, §3K's inventory issue, §3I's payment recording) must key off it.
- An offline sale's price/tax/discount is **a recorded fact, not a query** — sync must never
  re-price a queued transaction against whatever the master data looks like by the time it syncs.
  The transaction carries its fully-resolved line prices, tax amounts, and promotion results as
  computed offline at `occurred_at`; the server trusts and posts them as-is (subject to the
  oversell/redemption guards in §3K/§3R, which are policy checks, not re-pricing).
- Two timestamps are always recorded and kept distinct: `occurred_at` (the terminal's own clock,
  when the sale actually happened) and `synced_at` (server receipt time) — reporting (§3V) is
  always by `occurred_at`, never by `synced_at`, so a sale made at 2pm and synced at 6pm still
  reports as a 2pm sale.
- **Receipt numbering cannot use `SYSCONFIG.config_snums`** — that engine is an atomic, live-DB
  `SELECT ... FOR UPDATE` counter (`SYSCONFIG_SPECS.md` §3D) and cannot be allocated while
  offline. POS instead gives each terminal its own locally-owned sequence:
  `pos_terminals.receipt_prefix` (e.g. `POS01`) + a `last_local_seq` counter incremented
  client-side with no lock needed (single-device, no concurrent writers) — receipt numbers are
  `{prefix}-{seq}` (e.g. `POS01-000123`), globally unique per terminal without ever touching the
  server. This is a deliberate, explicit deviation from the tenant-wide-counter pattern every
  other module uses (same category of deviation `SYSCONFIG_SPECS.md` §3D already carves out for
  Legal's `protocol_books`, which also needed its own locking scope rather than the generic
  engine) — recorded here so it isn't mistaken for an oversight later.
- Conflict handling is narrow by design: the only real conflicts are the oversell (§3K) and
  redemption double-spend (§3R) cases, both already given explicit policy rules above — there is
  no generic "merge" step, because an offline POS transaction is an immutable fact once created
  (same append-only posture as every ledger in this platform), never edited post-hoc by sync.

## 3T. Security & Permissions

**Function / Features**
- **Menu-level trustee** (`menu.perm:POS_*` — `POS_TERMINAL`, `POS_SESSION`, `POS_SALE`,
  `POS_RETURN`, `POS_REPORTS`, `POS_ADMIN`) via `SYSCONFIG` trustee middleware, same as every
  other module (`CLAUDE.md` §4) — governs which POS *screens/actions* a logged-in user's role can
  reach at all.
- **In-transaction supervisor override** — a distinct concern from the above: a cashier logged in
  with `POS_SALE` rights hits a specific elevated action mid-transaction (discount above a
  threshold, item void, completed-sale void, refund, price override, cash-drawer-open outside a
  sale, reopening a closed session — the user's brief §23 matrix) and must capture a
  **supervisor/manager PIN** (a short numeric code checked against that user's own account, not a
  shared password) without logging the cashier out and back in. `pos_override_logs` (txn_id or
  session_id, action_type, requested_by (cashier), authorized_by (supervisor whose PIN was
  entered), reason nullable, occurred_at) — every override is its own audit row, mirroring the
  append-only audit posture every other module uses (`mes_audit_logs`, `config_audit_logs`, etc.).
- PIN entry is itself offline-capable (checked against the locally cached user/PIN hash from the
  last sync, §3S) — an override cannot be blocked by a connectivity outage any more than a sale
  can.

**Rules / Logic**
- The user's brief §23 role matrix (Cashier / Supervisor / Manager) is implemented as the
  **combination** of menu-level trustee (can this role reach the Sale/Refund screen at all) and a
  per-action override threshold config (`SYSCONFIG.config_consts`, e.g. `POS_DISCOUNT_PIN_ABOVE =
  10%`) — not a third, POS-specific role table.

## 3U. Reports & Dashboard

**Function / Features**
- Operational: sales by hour/cashier/terminal/branch/product/category, payment-method mix,
  discounts, returns, voids — all read models over §3F/§3I/§3K/§3L, composed from `DataTable`/
  `Panel`/`StatCard` (`CLAUDE.md` §9D4/5) per the shared design system.
- Financial: gross/net sales, discount, tax, COGS, gross profit/margin — COGS and margin pull from
  Inventory's own valuation (`INVENTORY_SPECS.md` §3I), never recomputed independently by POS.
- Cash: the per-session cashier report (§3D).
- Live "what's happening right now" dashboard (per the user's brief §25): today's sales/
  transactions/customers/avg-ticket/gross-profit, sales-by-hour, top products, payment mix — and,
  when `table_management`/`kds_enabled` are on, the restaurant-specific tiles (open tables, orders,
  kitchen queue depth, avg prep/table time, per the brief's restaurant dashboard example).
- All figures computed from §3F/§3I/§3K/§3L/§3C's own tables — no separate KPI storage table,
  same "cache/materialize only if a specific query proves too slow at real scale" posture
  `MES_SPECS.md` §3O already states for its own OEE dashboard.

---

# 4. Storage

> Tables and schema layout under tenant `POS` PostgreSQL schema.

**Master / config tables**
- `POS.pos_profiles` (§3A)
- `POS.pos_branches` (§3B — only if no other Core module's Branch concept exists to reuse; see §5
  note), `POS.pos_terminals`, `POS.pos_terminal_devices` (§3B, §3Q)
- `POS.pos_weighted_barcode_templates` (§3E)
- `POS.pos_favorite_items` (§3E)
- `POS.pos_promotion_rules` (§3H, Phase 2)
- `POS.pos_floors`, `POS.pos_tables` (§3M, Phase 2)
- `POS.pos_modifier_groups`, `POS.pos_modifiers`, `POS.pos_product_modifier_groups` (§3N, Phase 2)
- `POS.pos_kds_stations`, `POS.pos_product_kds_routing` (§3O, Phase 2)
- `POS.pos_loyalty_tiers` (§3R, Phase 2)

**Transaction tables**
- `POS.pos_sessions` (§3C) — references `HCM.employees`/user, `POS.pos_terminals`
- `POS.pos_cash_movements` — append-only (§3C/§3D)
- `POS.pos_txn_hdrs` (§3F/§3J) — references `POS.pos_sessions`, `POS.pos_terminals`,
  `CRM.partners` (nullable), `POS.pos_tables` (nullable), `SALES.price_lists`; carries
  `client_txn_uuid` (§3S) and `occurred_at`/`synced_at`
- `POS.pos_txn_lines` — references `INVENTORY.products`, `INVENTORY.stock_batches`/
  `stock_serials` (nullable)
- `POS.pos_txn_line_modifiers` (§3N, Phase 2)
- `POS.pos_kds_ticket_events` (§3O, Phase 2)
- `POS.pos_payments` (§3I) — references `POS.pos_gift_cards`/`pos_store_credits` when applicable
- `POS.pos_return_hdrs`, `POS.pos_return_lines` (§3L)
- `POS.pos_loyalty_accounts`, `POS.pos_loyalty_ledger` — append-only (§3R, Phase 2)
- `POS.pos_gift_cards`, `POS.pos_store_credits` (§3R, Phase 2)
- `POS.pos_override_logs` — append-only (§3U)

**Custom fields:** `pos_txn_hdrs` is registered as an extensible entity in
`CUSTOMFIELDS.field_defs`, same pattern as every other Core module's transaction header.

**Object File** (per `CLAUDE.md` §7B):
- POS owns no top-level R2 folder of its own — receipt PDFs (if archived beyond the printed copy)
  and any attached documents route through **DMS**'s structure (`DMS/POS/...` subfolder) with a
  `subject_type`/`subject_id` pointer, same as WNE/HCM/Sales/MES already do.

**Client-side (not tenant DB — see §3S):** IndexedDB stores mirroring the cached catalog/price/
promotion/customer subsets and the outbound sync queue; never the system of record, always
rebuildable from a fresh sync.

---

# 5. Technical Notes

- **One engine, profile-gated capabilities**: `pos_profiles` (§3A) is the single branch point,
  exactly like `production_model` is MES's — every other engine (cart, payment, inventory
  posting, AR boundary, offline sync) is profile-agnostic and reads/writes the same shared
  tables; only the UI mounts different sections based on the resolved capability set. This is the
  core architectural decision this module is built around, directly adopting the user's own
  brief's central recommendation ("one POS transaction engine + configurable sales modes +
  industry-specific extensions").
- **POS never owns a ledger it doesn't have to**: pricing is Sales's (§3G), stock/COGS is
  Inventory's (§3K), customer identity is CRM's (§3I), AR (when real) is Sales's (§3J),
  material composition for prepared items is PP's (§3P) — POS owns *transaction facts* (what was
  sold, to whom, for how much, paid how) plus the retail/restaurant-specific operational layers
  (session/cash, table/KDS, offline sync, loyalty) no other Core module has a reason to own.
- **The AR posting boundary (§3J) is the one place this module could most easily violate
  `CLAUDE.md` §11's already-resolved "Sales is the sole AR-side caller."** It doesn't, because
  fully-paid walk-in sales were never AR to begin with — they use the separate, always-available
  `JournalPostingRequested` contract, not `InvoiceRequested`. Any future change to this module
  that makes POS call `AccountingService::createInvoice(...)` or fire `InvoiceRequested` directly
  (bypassing Sales) is a regression against this spec, not a valid shortcut.
- **Branch concept**: no other Core module today owns a formal multi-branch entity beyond
  Inventory's Warehouses (`INVENTORY_SPECS.md` §3C) — POS introduces a minimal `pos_branches`
  lookup only if nothing better exists by the time this is built; if SysConfig or another module
  has since formalized a tenant-wide Branch/Location concept, POS should reference that instead
  of adding a second one (tracked in §7, not resolved here since it depends on build-order timing
  outside this module's control).
- **PIN check is a lightweight local credential**, distinct from full platform login — it exists
  purely for in-transaction override capture (§3U) and offline PIN checks (§3S), never as a
  parallel authentication system; a user without an active platform session cannot use PIN
  override to bypass login entirely.
- **Frontend**: dedicated POS shell (touch-first, large targets, minimal chrome) separate from
  `AppLayout` admin chrome for the actual selling surface (§3F/§3I/§3M-O) — same split
  `MES_SPECS.md` §5 already documents for its own Shop Floor layout — while all back-office POS
  screens (terminal/profile admin, promotion rules, reports, dashboard) stay composed from the
  shared design system (`DataTable`, `Panel`, `StatCard`, `StatusBadge`, `CLAUDE.md` §9D).
- **Append-only tables**: `pos_cash_movements`, `pos_loyalty_ledger`, `pos_override_logs`, and
  every `*_events` table are never updated/deleted in place, matching the platform-wide ledger
  discipline (`INVENTORY.stock_ledger`, `MES.mes_prod_events`, `SYSCONFIG.config_audit_logs`).
- **REST API surface is a deliberate, narrow exception** to `CLAUDE.md` §2's "Inertia for web,
  REST only for a real non-Inertia client" rule — justified the same way Legal's field-visit
  mobile API was (`LEGAL_SPECS.md` §3M/its own "Why REST" note): offline tolerance is a genuinely
  different client shape, not a stylistic preference. `api/v1/pos/*`, Sanctum bearer +
  `X-Tenant-Id`, same middleware stack already built for Legal — no new auth mechanism.
- **Async where volume demands it**: session-close journal posting (§3J) and sync-queue
  processing (§3S) go through Redis queues (`CLAUDE.md` §3), never a synchronous request the
  cashier waits on, same "don't block the operator-facing path" discipline `MES_SPECS.md` §5
  states for its own IoT ingestion.
- **Menu/permission codes**: `menu.perm:POS_*` (§3U) via SYSCONFIG trustee middleware.
- **Plan gating**: not yet added to `config/tenant_modules.php` — see §7 Open Items.

---

# 6. Build Order

> Recommended sequence for implementing this module's own pieces, and why. Internal to POS — see
> `CLAUDE.md` §5 for platform-wide sequencing (POS is not yet placed there, §7).

1. **POS Profile & capability matrix (§3A)** — nothing else can conditionally render without it;
   seed the two default profiles (Convenience Store, Restaurant) immediately.
2. **Terminal / Branch / Register topology (§3B) and POS Session (§3C/§3D)** — every transaction
   needs an open session on a real terminal to attach to.
3. **Product Catalog & Search (§3E)**, reusing Inventory's existing product/barcode/UoM master —
   the weighted-barcode template table is the only new schema here.
4. **Cart Engine (§3F)** and **Pricing (§3G)** — build against Sales's existing Price List engine
   before touching Promotion (§3H is Phase 2 and depends on the cart already working).
5. **Offline Architecture core (§3S)** — build this *alongside* steps 3–4, not after. Retrofitting
   offline onto an already-built online-only cart/catalog is exactly the rework this spec's
   phasing (§2) exists to avoid; the IndexedDB cache and sync queue need to exist before Payment/
   Inventory posting are wired, so those two can be built offline-first from the start.
6. **Payment Engine and Customer Identification (§3I)** — depends on cart + offline core.
7. **AR/Revenue Posting Boundary (§3J)** — depends on Payment (needs to know what was actually
   collected) and Session (the session-close trigger). Build both posting paths together, not one
   now and the other "later" — the walk-in path alone is what makes the on-account path's
   necessity legible in code review.
8. **Inventory Posting (§3K)** — depends on §3J existing conceptually (which lines are "real"
   sales vs. still-parked) even though the two are separate calls.
9. **Returns/Refunds (§3L)** — depends on §3J/§3K both existing, since a return reverses both.
10. **Hardware adapters (§3Q)** — receipt printer + cash drawer + scanner first (Phase 1
    essentials); kitchen printer/customer display/scale/card-terminal land with their respective
    Phase 2/3 features.
11. **Security & Permissions (§3U)** — menu perms can be wired incrementally from step 1 onward;
    the override-log table and PIN flow land once there's a real elevated action (discount, void)
    to gate, around step 6–7.
12. **Reports & Dashboard (§3U)** — pure read models, ship last in Phase 1 once the underlying
    transaction tables have real data.
13. **Phase 2**: Restaurant (§3M/§3N/§3O) as one connected slice — Floor/Table first (nothing else
    in the restaurant slice works without a table to attach an order to), then Modifiers, then
    KDS. Promotion Engine (§3H) and Loyalty/Gift Card (§3R) can build in parallel with the
    Restaurant slice, since neither depends on it.
14. **Recipe Consumption (§3P)** ships whenever PP's `PpService::getActiveRecipe()`/
    `scaleRecipe()` contract is real (`PP_SPECS.md` §7 tracks that implementation) — gate it as
    optional-module until then, exactly like every other PP-dependent caller in this platform.

---

# 7. Open Items

- [x] **`CLAUDE.md` §4/§5/§7A placement** — POS is now listed among the tenant DB schemas (§4,
      §7A) and the platform build order (§5), placed immediately after HCM — the last of its hard
      dependencies (Inventory, CRM, Sales, Accounting, WNE, DMS, HCM) in build-order sequence —
      and well before Payroll/Performance/PP/MES/AIInsight, none of which it depends on.
- [x] **`config/tenant_modules.php` entry** — added to `full` only, same placeholder-bundle
      posture PP/MES already have. A narrower dedicated `retail`/`fnb` plan bundle (Inventory +
      CRM + Sales + Accounting + WNE + DMS + HCM + POS + Design System) remains a real candidate
      for when a real retail/F&B tenant is a prospect — not guessed at now, same "don't guess a
      plan tier before a real tenant" posture `MES_SPECS.md` §7 already recorded for its own
      manufacturing plan.
- [ ] **Branch master resolution** — §5's note: whether POS introduces its own minimal
      `pos_branches` lookup or a tenant-wide Branch concept lands elsewhere first, is a
      build-order-timing question, not a POS design question. Revisit at build time.
- [ ] **Cashier identity source** — §3C assumes `HCM.employees` when HCM is installed; a tenant
      running POS without HCM (e.g. a very small shop on a narrow plan) needs a fallback to plain
      `SYSCONFIG` platform users. Both should work; which is the default needs a decision once the
      `retail`/`fnb` plan's actual module bundle (above) is finalized.
- [ ] **Loyalty's long-term home** — §3R notes POS owns Loyalty today because no other Core module
      has claimed it; if a future e-commerce/omnichannel need (§2 Phase 3) requires loyalty
      earning/redemption outside a physical POS transaction, promoting it to its own concern (or
      to CRM) is a real option to revisit then, not decided now.
- [ ] **`PpService` contract implementation** — §3P calls `PpService::getActiveRecipe()`/
      `scaleRecipe()`; tracked once already in `PP_SPECS.md` §7 (per the same convention
      `MES_SPECS.md` §7 uses), not duplicated here.
- [ ] **Weighing-scale and payment-gateway live integrations** — explicitly Phase 3 (§2); Phase
      1/2 ship with manual entry for both, by design, not as a gap.
