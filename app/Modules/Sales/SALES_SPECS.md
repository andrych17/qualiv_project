# Sales Module
## Sales Management — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every vertical (Legal today; Property, and others later) eventually sells something — a
service engagement, a retainer, a unit, a subscription — and the moment a deal moves past
"who is this person" (CRM's job) into "what are we selling them, for how much, and have they
paid," it needs a dedicated order-to-cash engine. Left unsolved centrally, this repeats the
same anti-pattern WNE, DMS, and CRM were each built to avoid:

- Each vertical invents its own quote/invoice numbering, pricing logic, and payment tracking —
  no shared reporting, no consistent revenue recognition, no reusable PDF templates.
- Customer data would get duplicated again (a second "who is this" table) unless Sales strictly
  reuses CRM's `partners` registry — CRM exists specifically so this never has to happen twice.
- No unified view of "what's owed to us" (AR aging) or "what did we sell this month" across
  verticals — critical for a solo dev who is also the business owner.
- Recurring revenue (retainers, subscriptions, maintenance contracts) has no home — every
  vertical would bolt on its own half-built recurring billing.
- Sales incentive tracking (commissions) is either done in a spreadsheet or not at all —
  neither is sellable as part of a "professional ERP."

**Client requirements:**
- Multi-tenant aware, same as every other Core module — tenant-scoped via DB-per-tenant
  isolation (no `tenant_id` column, per `CLAUDE.md` §4/§7).
- Must work **standalone** — a tenant can run Sales with just CRM installed (quotes → orders →
  invoices → payments), with no vertical module present, since it's sellable as its own line
  item exactly like DMS and Schedule.
- Must also integrate cleanly with any vertical: a Legal case can spawn a Sales Order (e.g.
  billing a retainer), a Property unit sale/lease can flow through the same quote-to-cash
  pipeline — without Sales knowing anything about Legal or Property internals.
- Customers are never re-modeled here — **Sales consumes `CRM.partners`** (role = Customer),
  full stop. No parallel customer table.
- Full quote-to-cash lifecycle: Opportunity → Quotation → Sales Order → Delivery → Invoice →
  Payment, with Returns and Credit control layered on top. **Invoice, Payment, and the AR
  ledger are owned by the Accounting module, not Sales** — Sales orchestrates *when* and
  *what* to bill (from an order, a delivery, or a recurring schedule) and hands off to
  Accounting via `InvoiceRequested`/`PaymentRequested`, the same seam `ACCOUNTING_SPECS.md`
  §3R already reserves for exactly this. This mirrors the "one ledger, many requesters" rule
  Accounting already applies to Legal case billing — Sales is the second, concrete consumer of
  that seam, not a parallel implementation of it.
- **Delivery/fulfillment and the physical stock ledger are owned by the Inventory module, not
  Sales**, when Inventory is installed — the same "one ledger, many requesters" seam applied
  one layer earlier. Sales's Delivery Engine orchestrates *what* is being shipped and drives
  customer-facing status/tracking, and hands off the actual stock decrement to Inventory via
  `InventoryService::issue()` at ship-confirm. A tenant running Sales without Inventory still
  gets full delivery tracking — see §3H/§5.
- Recurring revenue (Contracts & Subscriptions) must drive recurring billing automatically, not
  require a human to remember to invoice every month.
- Commission calculation must be auditable and tied to actual invoiced/paid revenue, not just
  order value (a cancelled/returned order shouldn't have paid out a commission).
- Credit control must be able to **block** an order (with an approval override path), not just
  passively report exposure.

# 2. Goals

> Designated features. MVP-first — ship a usable order-to-cash loop fast, defer the deep
> revenue-ops tooling (dynamic pricing, gateway integrations, forecasting models) to Future
> Version.

**MVP (ship-ready, quick implementation)**

- **Sales Master** — Price Lists (per customer/territory), Sales Teams, Territories. No new
  Customer table — Customers are `CRM.partners` filtered to role = `Customer`.
- **CRM Integration** — Opportunity pipeline sitting on top of CRM's existing Lead → Convert
  flow; Quotations attach to Opportunities or Customers directly; a minimal read-only Customer
  Portal (view quotes/orders/invoices/tracking via signed link).
- **Quotation Engine** — versioned estimates, revision history (never overwritten, same
  immutable-version pattern as DMS), optional WNE approval step, one-click convert to Sales
  Order.
- **Sales Order Engine** — order header/lines, status lifecycle, links out to Delivery and
  Billing.
- **Pricing & Promotions** — price-list resolution per customer/territory, simple
  percentage/fixed discounts, date-bound promo codes.
- **Delivery Engine** — pick → pack → ship → delivered lifecycle, manual carrier/tracking
  entry, partial deliveries against an order. When **Inventory** is installed, marking a
  delivery `shipped` posts the actual stock decrement via `InventoryService::issue()` (see
  §3H) — Sales does not maintain a second definition of "what's in stock."
- **Billing Engine (request orchestrator, not a ledger)** — decides *when* an order/delivery/
  recurring schedule is ready to bill and fires `InvoiceRequested` into **Accounting**, which
  performs the actual invoice creation, PPN/tax treatment, Faktur Pajak generation, and GL
  posting. Sales stores no invoice header/line/payment rows of its own — see §3I and §5.
- **Returns Engine** — RMA-style header/lines against an original order/invoice, refund or
  replacement path.
- **Customer Credit Engine** — credit limit + aging buckets, hard block on new orders over
  limit with a WNE-routed override approval.
- **Commission Engine** — flat/tiered plans per team or rep, calculated off invoiced (not
  just ordered) revenue, batch settlement with approval.
- **Contracts & Subscriptions Engine** — contract header + recurring lines, drives the
  recurring billing generator, simple lifecycle (draft → active → renewed → cancelled →
  expired).
- **Analytics** — dashboard cards + a simple pipeline funnel; deep BI deferred to a future
  dedicated Performance/BI module rather than built twice.

**Future Version (post-launch, once there's real usage volume/revenue to justify the build)**

- **Payment gateway integration** (Stripe/Xendit/local rails) — MVP is manual payment
  recording; a natural extraction candidate later (external API dependency, PCI-adjacent
  isolation benefit, per `CLAUDE.md` §2).
- **Tax engine** (multi-jurisdiction VAT/GST rules) — MVP uses a flat tax rate field per line;
  a real tax engine is its own justified microservice if the platform expands beyond one
  jurisdiction's rules.
- **Carrier API integration** (live tracking webhooks, label printing, route optimization) —
  MVP is manual tracking-number entry.
- **Dynamic/tiered/bundle pricing rule engine** — MVP is flat price-list + simple discount.
- **Usage-based billing & mid-contract proration** — MVP subscriptions are flat recurring
  amounts only.
- **Full self-service Customer Portal** (online quote approval, online payment, self-serve
  returns) — MVP portal is read-only.
- **Forecasting / weighted pipeline value / revenue prediction models** — MVP dashboard is
  descriptive (what happened), not predictive.
- **Multi-currency conversion engine** — MVP assumes single tenant currency (price lists can
  carry a currency tag for future-proofing, but no live FX conversion).
- **Automated credit scoring** — MVP credit limit is a manually set field.
- **Multi-level/split commissions, SPIFFs** — MVP is single-rep, single-plan commission only.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB
> design.

## 3A. Main Dashboard

**Function / features**
- At-a-glance revenue health: open quote value, open order value, revenue MTD, overdue
  invoice total, customers over credit limit, open returns.
- "My work" surface: quotes awaiting my approval, orders I own, commission settlements pending
  my sign-off — unified queue, same pattern as CRM's dashboard.
- Simple pipeline funnel: Opportunity stages → Quoted → Ordered → Invoiced → Paid, as counts
  and value.

**Layout**
- Top: summary cards (per above).
- Main: tabbed table — "My Opportunities" | "My Quotes" | "My Orders" | "Overdue Invoices".
- Every row uses the shared **Status Rail** (per `DESIGN.md`), colored by state — consistent
  with every other module's list view.

**Rules / logic**
- Tenant-scoped automatically (DB-per-tenant — no app-level filter needed, same as CRM/
  Schedule/DMS).
- Credit-blocked customers and overdue invoices surface first regardless of sort, mirroring
  CRM's SLA-breach-first rule.

## 3B. Sales Master

**Purpose:** the configuration layer every other engine reads from.

- **Price Lists** — header (name, currency, effective dates, territory/customer-segment scope)
  + lines (item/service reference, price). A customer/order resolves to exactly one active
  price list at a time (explicit assignment, falling back to a tenant default).
- **Sales Teams** — team header + member list (users), each team optionally tied to a
  Territory for routing/reporting.
- **Territories** — simple tenant-editable lookup (region/segment), assignable to a Customer
  (via a `CRM.partners` custom field, reusing `CUSTOMFIELDS` rather than a new column on a
  Core-owned table) and to a Sales Team.
- **Customers** — not a table. A view/query over `CRM.partners` filtered to
  `role = Customer`, joined to Sales-owned data (credit profile, assigned price list,
  territory, assigned rep/team) via `SALES.customer_sales_profiles` (see §4) — same
  "one store, many views" pattern DMS uses for module-embedded documents.

**Rules / logic**
- Assigning a Territory/Sales Team/Price List to a customer writes to
  `customer_sales_profiles`, never to `CRM.partners` — Sales never migrates CRM's schema.

## 3C. Opportunity Management (CRM Integration)

**Purpose:** the sales-specific pipeline that begins where CRM's Lead pipeline ends — a real,
qualified chance to sell something, whether or not a Lead was involved.

- Fields: name, `customer_id` (nullable — can start against a prospect not yet a Customer),
  `lead_id` (nullable, set if it originated from a converted CRM Lead), stage (New →
  Qualifying → Quoted → Won → Lost), owner (rep/team), estimated value, expected close date,
  loss reason (on Lost, same reason-code pattern as CRM Leads).
- Board (Kanban) and list views, same shared components as CRM's Lead board.
- Quotations attach to an Opportunity (or can be created directly against a Customer with no
  Opportunity, for repeat/simple sales — Opportunity is a convenience layer, not a hard
  requirement).

**Rules / logic**
- Winning an Opportunity does not itself create anything — it's the Quotation → Sales Order
  conversion that generates real transactions; "Won" is a pipeline/reporting state.
- Opportunity value is informational only in MVP (no weighted forecasting — see Future
  Version).

## 3D. Customer Portal (CRM Integration, read-only MVP)

- Signed-link or lightweight login access for a Customer to view their own Quotes, Orders,
  Invoices (with payment status), and Delivery tracking — no ability to act (approve/pay) in
  MVP.
- Reuses the same signed-UUID-token pattern Schedule uses for ICS feeds
  (`sales_portal_tokens`), so access can be revoked without touching platform auth.

## 3E. Quotation Engine (Estimates, Revisions, Approvals)

- Header: `customer_id`, `opportunity_id` (nullable), price list, validity date, status
  (draft → sent → approved → accepted/declined → expired → converted), revision number.
- Lines: item/service, qty, unit price (pre-filled from price list, overridable), discount,
  tax, line total.
- **Revisions**: editing a `sent` quote creates a new immutable revision (increment
  `revision_no`), never overwrites — identical philosophy to DMS document versioning. The
  customer-facing PDF always reflects the current revision; prior revisions remain visible in
  history.
- **Approval**: optional — a tenant can configure discount-threshold-triggered approval via
  WNE (`WorkflowRequested`, `workflow_code = sales.quote_approval`); quotes under the
  threshold skip straight to `sent`.
- **PDF & attachment**: quote PDF generated and stored via `DocumentService::upload()` (DMS),
  `subject_type = 'sales.quot_hdrs'` — Sales does not build its own file storage.
- **Convert to Order**: one action, copies lines as-is into a new Sales Order, marks quote
  `converted`, links back (`quot_hdrs.converted_so_id`).

**Rules / logic**
- An expired quote can be cloned into a new draft quote in one action rather than requiring
  re-entry.

## 3F. Sales Order Engine (Processing & Fulfillment)

- Header: `customer_id`, source (`quote_id` nullable — direct orders allowed), price list
  (locked at order time), status (draft → confirmed → partially fulfilled → fulfilled →
  cancelled), `subject_type`/`subject_id` (optional polymorphic link back to a vertical record,
  e.g. `legal.case_hdrs` — same seam as every other module).
- Lines: item/service, qty ordered, qty delivered (rolls up from Delivery), qty invoiced (rolls
  up from Billing), unit price, discount, tax.
- Confirming an order runs the **Credit Check** (3J) synchronously — a customer over their
  credit limit cannot confirm without an approval override.
- Order detail view: header + lines + linked Deliveries tab + linked Invoices tab + Activity
  Timeline (shared component, same as CRM cases).

**Rules / logic**
- Cancelling an order after partial fulfillment/invoicing is blocked — must go through Returns
  instead, to preserve a clean audit trail (never silently erase what already shipped/billed).

## 3G. Pricing & Promotions Engine

- Price resolution order: explicit line override → customer's assigned Price List → tenant
  default Price List.
- **Discounts**: percentage or fixed, at line or order level, optionally date-bound.
- **Promo codes**: `promo_codes` table (code, discount type/value, valid from/to, usage limit,
  usage count), applied at Quotation or Order entry, validated at apply-time (expired/exhausted
  codes rejected with a clear error per `DESIGN.md` voice guidance).

**Rules / logic**
- Promotions are additive to price-list pricing, never edit the price list itself — keeps the
  price list as the stable source of truth.

## 3H. Delivery Engine (Pick / Pack / Ship / Tracking)

- Header: `so_id`, status (pending → picked → packed → shipped → delivered → cancelled),
  carrier (free text/lookup), tracking number, shipped date, delivered date, source
  warehouse/location (shown only when **Inventory** is installed — defaults to the tenant's
  configured default warehouse, manually overridable; hidden entirely if Inventory isn't
  installed).
- Lines: order line reference, qty shipped this delivery (supports partial/split shipments —
  one order can have N deliveries).
- **Posting to Inventory (when installed):** marking a delivery `shipped` calls
  `InventoryService::issue(...)` (`INVENTORY_SPECS.md` §3E/§5) with the shipped lines and
  source location — Inventory creates its own `goods_issues`/`stock_ledger` entry (movement
  type `issue`), consumes valuation layers per its costing method, and returns its id, stored
  on `dlv_hdrs.inventory_goods_issue_id` (informational reference, not an enforced FK, since
  Inventory is an optional install) for traceability. If Inventory blocks the issue (requested
  quantity exceeds available at that location), the `shipped` transition is rejected with the
  same clear error `INVENTORY_SPECS.md` §3E already defines, rather than Sales silently
  recording a shipment that isn't physically possible. If Inventory is not installed, marking
  `shipped` proceeds exactly as before, with no physical-stock effect.
- Marking `shipped` fires `schedule.item_created`-style internal event
  (`sales.delivery_shipped`) → WNE (if enabled) notifies the customer/portal with tracking
  info — unchanged, fires regardless of whether the Inventory call above also ran.

**Rules / logic**
- `so_lines.qty_delivered` is a derived rollup from `dlv_lines`, recalculated on every delivery
  status change — never manually edited on the order. This remains Sales's own figure
  regardless of Inventory's install status — "how much has been shipped to this customer" is
  an order-fulfillment question Sales answers itself.
- Once Inventory posts its own ledger entry from a shipment, Inventory's `stock_ledger` becomes
  the authoritative figure for on-hand quantity and valuation from that point forward — the
  same split already established for Purchase's Goods Receipt
  (`PURCHASE_SPECS.md` §3E): two different questions, answered by the module that actually
  owns each one.
- Fully delivering all lines auto-updates the parent order status to `fulfilled`.

## 3I. Billing Engine (Invoice Request Orchestration)

**Purpose:** decide *when* something is ready to bill and hand off to **Accounting**, the
single AR ledger of record for the platform (`ACCOUNTING_SPECS.md` §3D). Sales does not
maintain its own invoice or payment tables — a deliberate correction: an independent
Sales-owned ledger would break Accounting's control-account guarantee ("AR balance is always
exactly the sum of open invoice balances") and risk issuing a customer invoice without correct
PPN/Faktur Pajak treatment, which Accounting's own spec calls a compliance liability from the
first transaction, not a gap safe to patch in later.

- **Trigger sources**: a confirmed Sales Order ready to bill (in full or by delivered lines), a
  Delivery marked `shipped`/`delivered`, a Contract & Subscription's recurring schedule
  (§3L) reaching its `next_bill_date`, or an external billable request from any Core or
  Vertical module via `SalesOrderService::createFromExternalRequest(...)` (preferred,
  same-process) or the `SalesOrderRequested` event (decoupled). Sales defines this as a
  generic entry point (payload: `subject_type`/`subject_id`, customer partner reference, line
  items, description) that any caller can populate — the same "Core defines the contract, any
  caller populates it" pattern already used for Accounting's `InvoiceRequested`/
  `BillRequested`. Legal is the first concrete user of this path (a matter ready to bill calls
  it directly, per `LEGAL_SPECS.md` §2), but Sales requires no Legal-specific code to support
  it. The resulting Sales Order (`subject_type`/`subject_id` pointing back to the originating
  record, per §3F) then flows through the same confirmed-and-ready-to-bill path as any other
  order, so the Billing Engine has exactly one shape of input regardless of where the request
  originated.
- **On trigger**: Sales calls `AccountingService::createInvoice(...)` / fires
  `InvoiceRequested` (`subject_type = 'sales.so_lines'`, `subject_id` = the order line(s) being
  billed) with customer (`CRM.partners` reference), line items, amounts, and tax codes.
  Accounting creates the `ar_invoices` row, computes PPN output tax, generates a Faktur Pajak
  if applicable, and posts the AR control-account journal — all per `ACCOUNTING_SPECS.md`
  §3D/§3M, not reimplemented here.
- **Deposits**: requested the same way, flagged `invoice_type = deposit` on the request (an
  additive field on `ACCOUNTING.ar_invoices`). The deposit is applied against a later final
  invoice using Accounting's existing payment-application mechanism (§3D) — Sales does not
  implement its own credit-balance logic.
- **Payment recording**: captured wherever the tenant actually takes payment (Sales UI,
  Customer Portal, or Accounting's own screens) but always written to
  `ACCOUNTING.ar_payments`/`ar_payment_applications` via `PaymentRequested` — never to a
  Sales-owned table.
- **Order/line status rollup**: Accounting fires `InvoicePosted` and `PaymentRecorded` events
  carrying the `subject_type`/`subject_id` back-reference; Sales subscribes to update
  `so_lines.qty_invoiced` and the order's billing status — Accounting never needs to know
  `SALES.so_lines` exists beyond that pointer.
- **Recurring billing**: `SALES.recurring_billing_schedules` (from Contracts, §3L) still drives
  a daily scheduled job — its only action on each due date is firing the same
  `InvoiceRequested` a manual order-to-invoice conversion would, not writing a row directly.
- **Invoice-overdue reminders**: Sales, not Accounting, owns customer-facing dunning
  communication (it's the customer-relationship module) — a scheduled job reads Accounting's
  AR aging (§3D) for the customers/orders Sales cares about and fires `sales.invoice_overdue`
  → WNE, same as before. Accounting's aging report stays the source of truth; Sales only reads
  it, never recomputes it.

**Rules / logic**
- If Accounting is not installed/enabled for a tenant, Sales cannot generate a real invoice —
  see §5 for why this specific action, not the whole Billing Engine's UI, is a hard dependency.
- Credit-note requests (from Returns, §3J) go through the same `AccountingService` facade —
  never a direct edit to an Accounting-owned row.

## 3J. Returns Engine (Returns, Refunds, Replacements)

- Header: original `so_id`/`invoice_id`, `customer_id`, reason code, status (requested →
  approved → received → refunded/replaced → closed).
- Lines: original order line reference, qty returned, condition notes.
- **Refund path**: requests a credit note from **Accounting** (`ACCOUNTING.ar_credit_notes`,
  §3D) against the original invoice, rather than Sales writing its own adjustment — same
  "Accounting is the ledger, Sales is the requester" rule as Billing (§3I).
- **Replacement path**: one action generates a new Sales Order pre-filled with the returned
  lines, `subject_type = 'sales.ret_hdrs'` linking back for traceability.

**Rules / logic**
- Approving a return above a configurable value threshold can route through WNE
  (`workflow_code = sales.return_approval`), same optional-approval pattern as Quotations.

## 3K. Customer Credit Engine (Limits, Aging, Approvals)

- `SALES.customer_credit_profiles`: `partner_id` (FK → `CRM.partners`), credit_limit,
  payment_terms_days, `on_hold` flag (manual override, blocks all new orders regardless of
  limit).
- **Aging report**: Sales does not compute this itself — it calls Accounting's AR aging
  report (`ACCOUNTING_SPECS.md` §3D) filtered to the customer, since `ar_invoices` is the only
  place open balances actually live now.
- **Credit check**: run synchronously on Order confirmation —
  `AccountingService::getOpenARBalance(partnerId) + this order's value > credit_limit` blocks
  confirmation with a clear error (per `DESIGN.md`: *"This order exceeds [Customer]'s credit
  limit by $X. Request an override or reduce the order."*) and offers a `WorkflowRequested`
  override (`workflow_code = sales.credit_override`) if the tenant has WNE enabled; without
  WNE, only an explicit `on_hold`-clearing admin action can bypass it. The credit *limit* and
  *on_hold* flag remain Sales-owned config (`customer_credit_profiles`) — only the
  balance-vs-limit arithmetic now reads from Accounting instead of a local invoice table.

**Rules / logic**
- Credit profile lives entirely in `SALES` schema, never on `CRM.partners` — Core CRM has zero
  knowledge of Sales-specific data, same one-way dependency rule as everywhere else.

## 3L. Contracts & Subscriptions Engine (Lifecycle & Recurring Revenue)

- Header: `customer_id`, contract name, term start/end, auto-renew flag, status (draft →
  active → renewed → cancelled → expired), linked Price List.
- Lines (`contr_subscriptions`): item/service, recurring amount, billing interval (monthly /
  quarterly / annual — simple enum, not RRULE), `next_bill_date`.
- Activating a contract seeds `recurring_billing_schedules` rows (one per subscription line)
  that the Billing engine's scheduled job consumes.
- Renewal: on approach of `term_end` with `auto_renew = true`, a job extends the term and
  fires a `sales.contract_renewed` notification (WNE); without auto-renew, fires
  `sales.contract_expiring` for manual follow-up.

**Rules / logic**
- Cancelling a contract stops future invoice generation immediately but never retroactively
  voids already-issued invoices — cancellation is forward-only, same non-destructive posture
  as everywhere else in the platform.

## 3M. Commission Engine (Sales Incentives & Settlements)

- `SALES.commission_plans`: name, basis (flat % or tiered by revenue band), applies to
  (Sales Team or individual rep), effective dates.
- Commission is calculated off **invoiced-and-paid** revenue (not order value), computed when
  Sales receives Accounting's `PaymentRecorded` event for an invoice whose `subject_type`/
  `subject_id` traces back to that rep's order — avoids paying commission on
  cancelled/unpaid/returned business, and avoids Sales needing its own payment-tracking table
  to know it happened.
- **Settlement**: a batch (`comm_settlements`, period-based — e.g. monthly) aggregates
  earned-but-unsettled commission per rep, status (draft → approved → paid), optionally routed
  through WNE for manager approval before payout.

**Rules / logic**
- A Return that refunds a paid invoice automatically reverses the associated commission on the
  next open settlement batch (never edits an already-paid settlement — a reversal line instead,
  same immutable-ledger discipline as Billing).

## 3N. Analytics (KPIs, Dashboards, Forecasting)

- MVP: the dashboard cards in §3A plus a simple funnel chart (Opportunity → Quoted → Ordered →
  Invoiced → Paid, count and value per stage).
- Deliberately **not** building deep BI/forecasting inside Sales — flagged to link to a future
  dedicated Performance/BI module (per the project's existing "on the horizon" notes) that can
  aggregate across Sales, CRM, and vertical modules in one place, rather than every module
  growing its own half-built analytics layer.

---

# 4. Storage

**Database (schema `SALES`, tenant DB — consistent with `CLAUDE.md` §7A):**

**Master / lookup / config tables**
- `SALES.price_lists`, `SALES.price_list_lines`
- `SALES.sales_teams`, `SALES.sales_team_members`
- `SALES.territories`
- `SALES.promo_codes`
- `SALES.commission_plans`
- `SALES.customer_sales_profiles` — `partner_id` (FK → `CRM.partners`), territory, sales team,
  assigned price list, assigned rep.
- `SALES.customer_credit_profiles` — `partner_id` (FK → `CRM.partners`), credit_limit,
  payment_terms_days, `on_hold`.
- `SALES.sales_portal_tokens` — signed UUID tokens for Customer Portal access.

**Transaction / log tables** (domain-prefixed two-part, matching WNE/CRM convention)
- `SALES.opp_hdrs` — Opportunities.
- `SALES.quot_hdrs`, `SALES.quot_lines` — Quotations (versioned; `revision_no`,
  `converted_so_id`).
- `SALES.so_hdrs`, `SALES.so_lines` — Sales Orders.
- `SALES.dlv_hdrs` (includes nullable `inventory_goods_issue_id` — informational reference to
  `INVENTORY.goods_issues.id` when Inventory is installed and the delivery has been posted
  there; not an enforced FK, since Inventory is an optional install for Sales),
  `SALES.dlv_lines` — Deliveries.
(removed — invoices, invoice lines, and payments are owned by `ACCOUNTING.ar_invoices` /
`ACCOUNTING.ar_invoice_lines` / `ACCOUNTING.ar_payments`, referenced from Sales only via
`subject_type`/`subject_id`, never a local table. See §5.)
- `SALES.recurring_billing_schedules` — drives the recurring invoice generator.
- `SALES.ret_hdrs`, `SALES.ret_lines` — Returns.
- `SALES.comm_settlements`, `SALES.comm_settlement_lines` — Commission settlement batches.
- `SALES.contr_hdrs`, `SALES.contr_subscriptions` — Contracts & Subscriptions.

**Object file storage** (per `CLAUDE.md` §7B) — Sales does not store files itself; quote PDFs,
signed contracts, and delivery notes are stored via **DMS** (`DocumentService::upload()`),
under `tenant_{id}/DMS/Sales/...`, with `subject_type`/`subject_id` pointing back to the
relevant Sales record — no parallel storage path.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Modular monolith module at `app/Modules/Sales/`, same shape as WNE/
DMS/CRM/Schedule (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`,
`Routes/`). No microservice extraction at MVP — Sales is CRUD + calculation-heavy services
(pricing, credit check, commission calc), none of which need a different runtime yet. Payment
gateway and tax-engine integrations are the two pieces flagged as **justified future
extractions** per `CLAUDE.md` §2 (external API surface, isolation benefit for
payment-adjacent data) — not built now.

**Dependency posture (important, differs per dependency):**
- **CRM — hard dependency.** Sales cannot function without a Customer concept, and that
  concept is `CRM.partners`. Sales schema tables FK directly into `CRM.partners.id`
  (cross-schema FK, Core-to-Core, same precedent as CRM/DMS/WNE already referencing each
  other within a tenant DB) rather than duplicating contact data. A tenant cannot enable Sales
  without CRM enabled.
- **Accounting — hard dependency for Billing only.** Sales cannot generate a real invoice,
  record a payment, or check AR aging/credit exposure without Accounting enabled — those
  actions call straight into `AccountingService`/fire `InvoiceRequested`/`PaymentRequested`,
  with no local fallback (same "no parallel ledger" reasoning as the Payroll↔HCM fix in
  `PAYROLL_SPECS.md` §5). Opportunity, Quotation, Sales Order, Pricing, and Delivery are
  **not** gated on Accounting — a tenant can run Sales+CRM alone through "order confirmed" and
  only needs Accounting once something has to actually become a real, tax-correct invoice.
- **WNE — soft dependency.** All approval steps (quote approval, credit override, return
  approval, commission settlement approval) and all notifications (order confirmed, delivery
  shipped, invoice overdue, contract expiring) are published as internal events
  (`sales.*`) and consumed by WNE **only if enabled** for the tenant — Sales has zero
  compile-time dependency on WNE classes, mirroring Schedule's posture exactly. Without WNE,
  approval-gated actions simply require an explicit admin action instead of a routed workflow.
- **DMS — soft dependency.** Quote PDFs, contracts, and delivery notes attach via
  `DocumentService` if DMS is enabled; without DMS, Sales falls back to generating a
  downloadable PDF on the fly with no persistent version history (degraded, not broken).
- **Inventory — soft dependency, scoped to physical fulfillment only.** The Delivery Engine
  (§3H) calls `InventoryService::issue()` when Inventory is enabled, to post the real stock
  decrement and valuation on ship-confirm; every other part of Sales (Opportunity, Quotation,
  Sales Order, Pricing, Billing, Returns, Commission) is unaffected by whether Inventory is
  installed, since `so_lines.qty_delivered` always derives from Sales's own `dlv_lines`, never
  from Inventory's ledger. A Sales tenant selling non-physical services (e.g. Legal retainers)
  has no reason to install Inventory at all — Delivery is only meaningfully used by tenants
  selling physical goods.
- **Schedule — soft dependency.** Not required for MVP (recurring billing uses its own simple
  interval field, deliberately not RRULE, to avoid a hard coupling); if Schedule is enabled
  later, delivery due dates / contract renewal dates could optionally surface on the shared
  calendar as a Future Version enhancement.

**Internal facade/service** — `SalesOrderService::confirm(...)`,
`SalesOrderService::createFromExternalRequest(...)` (the generic entry point for a Core or
Vertical module's billable request — see §3I), `QuotationService::convertToOrder(...)`,
`BillingService::generateInvoice(...)`, `CreditService::check(...)`,
`CommissionService::calculate(...)` — preferred integration point for other Core/vertical
modules (e.g. a Legal case triggering a retainer Sales Order via
`createFromExternalRequest(...)`).

**Internal event bus** — publishes `OpportunityWon`, `QuotationSent`, `QuotationConverted`,
`SalesOrderConfirmed`, `sales.delivery_shipped`, `sales.invoice_overdue` (derived from
Accounting's AR aging, not a local invoice table), `sales.contract_renewed`,
`sales.contract_expiring`, `sales.credit_blocked`; **consumes** its own generic
`SalesOrderRequested` (§3I — the vertical/Core billable-request entry point), the same kind of
self-owned contract as Accounting's `InvoiceRequested`/`BillRequested`. Sales also
**subscribes** to Accounting's `InvoicePosted`/`PaymentRecorded` (to update
`so_lines.qty_invoiced` and trigger Commission, §3M) — justified because Accounting is a Core
peer, not a vertical, and this is a read-only status echo, not Accounting reaching into Sales.
Vertical modules never fire an event *named for Sales's internals*, and Sales never listens for
an event *named for a Vertical module's internals* — the same one-way "Core has zero knowledge
of Vertical modules" rule as CRM/DMS/WNE (`CLAUDE.md` §2). `SalesOrderRequested` doesn't
violate this: it's a **Sales-owned, generically-shaped contract**
(`subject_type`/`subject_id` + line items + description) that any caller — Core or Vertical —
populates and fires, exactly like Accounting's `InvoiceRequested`/`BillRequested`, WNE's
`WorkflowRequested`/`NotificationRequested`, and DMS's `DocumentAttachRequested`. Sales still
never reaches backward into a Vertical module's schema or subscribes to a Vertical-namespaced
event by name.

**Vertical linkage without coupling:** `so_hdrs`, `quot_hdrs`, and `ret_hdrs` all carry
`subject_type`/`subject_id` as plain informational columns, not foreign keys — identical seam
to CRM's `svc_cases` and WNE's workflow instances. A Legal matter billing a retainer calls
`SalesOrderService::createFromExternalRequest(...)` (§3I / facade list above), which sets
`subject_type = 'legal.matters'` (or `'legal.deeds'` for a deed-specific charge) on the
resulting Sales Order; Sales never needs to know the Legal schema beyond that pointer. The
same seam runs the other way for Billing: Accounting's `ar_invoices`/`ar_invoice_lines` carry
`subject_type = 'sales.so_lines'` back to the order line that was billed — Accounting never
needs to know Sales's schema beyond that pointer, and it's how Sales recognizes
`InvoicePosted`/`PaymentRecorded` events as "mine" without a hard FK either way.

**Non-destructive financial data:** invoices are voided, never deleted; quote revisions are
additive, never overwritten; commission reversals are new ledger lines, never edits to a paid
settlement — consistent with the audit-trail discipline established in DMS and CRM, and
necessary for a legal-buyer-conservative, financially-audited product.

**Suggested build order for Claude Code:** 3B (Sales Master) → 3E (Quotation, MVP without
approval) → 3F (Sales Order + Credit Check 3K) → 3G (Pricing, folded into 3E/3F) → 3H
(Delivery — wire the optional `InventoryService::issue()` call if Inventory is already live,
or ship 3H without it and add the call later, since it's purely additive) → confirm
Accounting's AR engine (§3D of `ACCOUNTING_SPECS.md`) is live → 3I (Billing — now an
`InvoiceRequested`/`PaymentRequested` orchestrator, not a ledger) → wire WNE
approvals into 3E/3F/3J once WNE integration is confirmed working → 3L (Contracts, feeding
3I's recurring generator) → 3J (Returns) → 3M (Commissions, driven by Accounting's
`PaymentRecorded`) → 3C/3D (Opportunity + Portal) → 3N (Analytics) — this ships a working
quote-to-cash loop (3B→3I) before any of the "nice to have but not blocking revenue" pieces,
with Accounting as the one hard prerequisite inside that loop.

**Marketability notes**
- Quote-to-cash + recurring billing + credit control is a strong differentiator against
  spreadsheet-based practice management — directly sellable to the Legal vertical as "bill
  your retainers and track who owes you money," without Sales needing to know what a
  "retainer" is (it's just a Contract + Subscription). Because Billing now always routes
  through Accounting, every Sales-generated invoice is PPN/Faktur-Pajak-correct by
  construction — a genuine differentiator to lead with, not just an internal consistency fix.
- Commission tracking is a natural upsell for any tenant with an outbound sales team,
  independent of vertical.
- Keeping Sales standalone-capable (works with just CRM, no vertical required) means it can be
  sold to a non-legal, non-property tenant later as a plain order-management product — the
  same reusability lever CRM and Schedule already provide.

**MVP bias note:** for first ship, Opportunities/Portal/Commissions/Contracts can all be
trimmed to their simplest form (flat records, no Kanban, no auto-renew automation, no
tiered commission) without touching the schema above — same "feature-flag reduction, not
re-architecture" posture already used for CRM's MVP scope.
