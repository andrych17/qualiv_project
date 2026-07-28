# Purchase Module
## Purchase & Procurement System — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every vertical this platform will ever sell — Legal today (office supplies, expert-witness
retainers, court-filing vendors, IT/software spend), Property tomorrow (maintenance vendors,
contractors, utilities) — eventually buys things from someone. Left unsolved centrally, this
repeats the same anti-pattern already avoided in WNE/DMS/CRM/Schedule:

- Each vertical invents its own "who do we buy from" list instead of reusing the unified
  Partner registry already built in **CRM** (a Vendor is just a `partner` with a `Vendor`
  role — no reason to duplicate that concept here).
- No consistent approval trail on spend — purchase decisions get made over email/WhatsApp,
  with no audit record, no budget check, and no way to prove compliance to an auditor or a
  legal-vertical client who cares about vendor conflicts-of-interest.
- No visibility into "what are we actually spending, with whom, and is it under contract" —
  spend leaks through maverick buying (off-catalog, off-contract purchases).
- No structured way to compare suppliers before committing — sourcing happens ad hoc, so
  price/quality leverage is lost.
- No shared place for the Purchase Order → Goods Receipt → Invoice three-way match, so
  invoice errors and duplicate payments go undetected until Finance finds them (or doesn't).
- Contract renewal dates live in someone's head or a spreadsheet, not a system that reminds
  anyone — auto-renewals get missed or compliance lapses go unnoticed.

**Client requirements:**
- Must work **standalone** — a tenant can run Purchase with nothing else installed (manual
  vendor entry, simple PO, manual receipt/invoice match) since it is sellable as its own line
  item, same posture as DMS and Schedule.
- Must integrate cleanly when other Core modules are present: **CRM** for the vendor/partner
  record, **WNE** for every approval step and notification, **DMS** for contract/document
  storage and retention, **Schedule** for renewal/audit/review reminders and RFx deadlines.
- Full traceability from Requisition → Sourcing (RFx) → Purchase Order → Goods Receipt →
  Invoice → (payment handoff) — a real audit trail, not just a status field.
- Budget/approval control before commitment, not after — a PO above threshold should not be
  issuable without the right sign-off, using the same workflow engine every other module uses.
- Strategic sourcing (RFI/RFQ/RFP with weighted scoring) for the buyer who wants to run a
  real competitive process, without forcing every tenant to use it for a $50 stationery order.
- Supplier self-service (portal) so vendors submit quotes, acknowledge orders, post shipment
  and invoice data themselves — cuts the buyer's admin load, and is a strong demo point.
- Supplier relationship management: certifications/insurance expiry, audit history, corrective
  actions, periodic scorecards — procurement risk management, not just transaction processing.
- Spend visibility: category (direct/indirect, CAPEX/OPEX), supplier concentration, spend vs.
  contract — the reporting a Finance stakeholder actually asks for.
- ESG/compliance flags (local-content %, sustainability docs, certifications) — increasingly a
  contractual requirement from *clients of* our clients, so it needs a home even if scoring
  logic stays simple at launch.
- AI-assisted procurement (forecasting, supplier recommendation, price-anomaly detection,
  duplicate-PR detection) is explicitly desired, but — per the project's AIInsights Core design
  — should reuse that shared "ask your data" infrastructure rather than Purchase building its
  own bespoke ML pipeline.
- Mobile-usable for the two tasks people actually do on a phone: approving something, and
  scanning a delivery at a dock/warehouse. A native offline app is not required to ship.

# 2. Goals

> Designated features. MVP-first — ship a genuinely usable procure-to-pay core fast; defer
> heavy sourcing/SRM/AI/ESG machinery to Future Version once there's real usage to justify it.
> This mirrors the MVP-bias precedent already set in `DMS_SPECS.md` (OCR/semantic search
> deferred) and `CRM_SPECS.md` (Kanban/merge trimmed for first ship).

**MVP (ship with/soon after Legal vertical launch)**
- **Requisition → PO core flow.** Purchase Requisition (PR) raised by any user/module →
  approval via **WNE** → converted to Purchase Order (PO) → sent to supplier → Goods Receipt
  → Invoice → **three-way match** (PO / GR / Invoice) with configurable tolerance.
- **Vendor = CRM Partner.** No separate vendor table — Purchase reuses `CRM.partners` filtered
  to partners holding a `Vendor` role, adding only procurement-specific attributes (payment
  terms, incoterms, tax status) in a `PURCHASE.vendor_profiles` extension table (1:1 with
  `CRM.partners`), same "extend, don't duplicate" pattern CRM already establishes for other
  modules' relationship to the Partner registry.
- **Simple RFQ.** Multi-supplier quote request with a due date and a flat comparison table
  (price, lead time, notes side by side). This alone covers the sourcing need for the large
  majority of purchases without building the full weighted RFI/RFP engine yet.
- **Basic catalog.** A flat `pur_catalog_items` list — item, default/preferred supplier,
  negotiated price, unit — enough to speed up repeat buying and flag "off-catalog" purchases.
  No punch-out integration yet.
- **Budget check (soft).** PR/PO checked against a simple period budget by category/cost
  center; over-budget flags a warning and routes for extra approval via WNE rather than
  hard-blocking — good enough for launch without building a full budgeting module.
- **Contract register (basic).** Contract header + document (stored via **DMS**, same pattern
  DMS already uses for retention/expiry) + start/end date + auto-renewal flag. Renewal
  reminder fires through **Schedule** (a recurring calendar item) → **WNE** notification —
  no new reminder mechanism invented here.
- **Exception alerts (the essentials).** Overdue approval, late delivery (GR past PO expected
  date), price variance beyond tolerance, unmatched invoice — all fire as `WorkflowRequested`
  / `NotificationRequested` events into **WNE**, exactly like every other module's exception
  handling. Budget overrun reuses the same soft-check above.
- **Mobile-usable, not mobile-native.** Approvals and Goods Receipt (including barcode/QR
  scan-to-receive via the device camera, and photo attachment on receipt) work on the existing
  responsive Vue/Inertia UI. No offline mode, no dedicated native app in v1.
- **Basic spend view.** Spend by supplier, by category (direct/indirect, CAPEX/OPEX — a simple
  tenant-editable classification on PR/PO lines, not an auto-classifier), and spend-vs-contract
  for contracts on file. Plain tables/charts, no predictive analytics yet.
- **Supplier document exchange (lightweight).** Suppliers can be emailed a secure upload link
  (no full portal login required for v1) to submit a quote, an order acknowledgment, or an
  invoice/document — stored via **DMS** with `owning_module = Purchase`. This gets 80% of the
  "supplier portal" value without building supplier authentication/session management yet.

**Future Version (post-launch, once there's real usage volume/revenue to justify the build)**
- **Full strategic sourcing (RFI/RFQ/RFP)** with structured weighted-criteria scoring
  (price/quality/delivery/ESG weight sliders), multi-round bidding, and sealed-bid handling.
- **Full supplier portal** — real supplier login/session, self-service quote submission, order
  ack, shipment tracking updates, invoice submission with status visibility, document exchange
  — an evolution of the MVP's link-based exchange, not a rebuild (see Technical Notes).
- **Supplier Relationship Management (SRM)** — certifications/insurance tracking with expiry
  alerts, scheduled audits, CAPA (corrective action / preventive action) workflows, periodic
  scorecards (on-time %, quality rejects, responsiveness) computed from live transaction data.
- **AI-assisted procurement** — demand forecasting, supplier recommendation, price-anomaly
  detection, duplicate-PR detection — built as read/analysis features on top of **AIInsights
  Core** (per-tenant "ask your data" Claude API integration already designed), not a
  standalone ML stack inside Purchase. See Technical Notes.
- **ESG & compliance scoring** — structured sustainability metrics, local-content percentage
  calculation/enforcement, regulatory document tracking with expiry — v1 only stores documents
  and free-form flags; scoring/enforcement logic is deferred.
- **Punch-out catalogs** — cXML/OCI punch-out to supplier e-catalogs (e.g. Amazon Business,
  Grainger-style). Real integration cost per supplier; not justified pre-revenue.
- **Hard budget enforcement**, multi-level/multi-currency budgets, budget carry-forward rules.
- **Native offline mobile** (queue scans/approvals locally, sync when back online) — v1
  assumes intermittent-but-present connectivity; true offline is a distinct, higher-cost build.
- **Automated three-way-match tolerance learning** and advanced spend analytics (supplier
  concentration risk scoring, category benchmarking, trend forecasting).

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard

**Function / features**
- At-a-glance procurement health: open PRs awaiting approval, open POs by status, GRs pending
  match, invoices pending match/approval, contracts expiring in next 30/60/90 days.
- "My work" queue: PRs/POs/invoices assigned to or awaiting me — same unified-queue pattern
  CRM's dashboard uses for leads/tickets/cases.
- Exception strip: overdue approvals, late deliveries, price variances, budget flags,
  unmatched invoices — pulled straight from the Exception Engine (3K), not recomputed here.

**Layout**
- Top: summary cards — Open PRs, Open POs, Pending Receipts, Pending Invoice Match, Contracts
  Expiring Soon.
- Main: tabbed table — "My Approvals" | "My POs" | "My Requisitions" | "Exceptions" — each row
  using the shared **Status Rail** (per `DESIGN.md`), same visual motif as every other module.
- Row click opens a drawer: header, line items, linked documents (via DMS), audit/activity
  timeline, related workflow status (via WNE).

**Rules / logic**
- Tenant-scoped by the DB-per-tenant boundary (no `tenant_id` column — see §5 note on
  reconciling with WNE's current pattern).
- "My work" resolves via direct assignment **and** role/team membership, same resolution
  pattern already used in WNE ("My Approvals") and CRM ("My work").

## 3B. Purchase Requisition (PR)

- Fields: requester, department/cost center, needed-by date, lines (item/catalog ref or
  free-text description, quantity, estimated unit price, category — direct/indirect,
  CAPEX/OPEX), `subject_type`/`subject_id` (optional polymorphic link back to the record that
  triggered it, e.g. a Legal case needing an expert witness — same seam pattern as every other
  module's optional vertical link).
- Soft budget check on submit (3F budget note) — warns, does not hard-block, in MVP.
- Duplicate-PR check in MVP is a simple rule (same requester + same catalog item + open PR
  within N days) flagged as a soft warning; the AI-based version is Future Version (3L).
- Submission fires `WorkflowRequested` (`workflow_code = purchase.pr_approval`) into **WNE** —
  Purchase does not implement approval logic itself, exactly like every other module's
  relationship to WNE.
- Approved PR can be converted directly to a PO, or routed into Sourcing (3C) first if the
  requester/buyer flags it for competitive quotes.

## 3C. Sourcing / RFx Engine

**MVP scope: RFQ only, flat comparison.**
- RFQ header: linked PR (optional — can also be standalone), due date, invited suppliers
  (from `CRM.partners` where role = Vendor), line items copied from the PR or entered directly.
- Supplier response capture: price, lead time, notes — per line, per supplier — via the
  lightweight document/response-link exchange described in §2 MVP (no portal login yet).
- **Comparison view**: flat side-by-side table, one column per responding supplier, cheapest
  cell highlighted per line — enough decision support without a scoring model.
- Award action: pick winning supplier(s) per line (split awards allowed) → generates the PO
  (3D) pre-filled from the RFQ award.

**Future Version additions (RFI/RFP + weighted evaluation):**
- RFI (pre-qualification, no pricing) and RFP (solution + pricing) as additional RFx types on
  the same header shape, differentiated by a `type` column — not a separate schema, so the
  MVP's tables don't need breaking changes later.
- Weighted evaluation: tenant-configurable criteria + weights (price/quality/delivery/ESG/
  compliance), scorecard per supplier response, auto-ranked composite score.
- Multi-round bidding and sealed-bid visibility rules (responses hidden from other bidders
  and from internal viewers until the round closes).

**Rules / logic**
- RFx due-date reminders and "supplier hasn't responded" nudges are calendar items in
  **Schedule**, notified via **WNE** — no bespoke reminder code in Purchase.

## 3D. Purchase Order (PO)

- Fields: supplier (`CRM.partners`), header (ship-to, bill-to, currency, incoterms, payment
  terms — defaulted from `PURCHASE.vendor_profiles`, overridable), lines (item/catalog ref or
  free-text, quantity, unit price, expected delivery date, tax), linked PR/RFQ award
  (optional), status (`draft → pending_approval → approved → sent → acknowledged →
  partially_received → received → closed → cancelled`).
- Approval routes through **WNE** (`workflow_code = purchase.po_approval`), typically
  threshold-based (amount above X requires a second approver) — the threshold rule itself
  lives in WNE's workflow configuration, not hardcoded in Purchase.
- "Send to supplier" delivers the PO PDF (generated, stored via **DMS**) via the supplier's
  preferred channel (email in MVP, using WNE's existing channel-driver pattern rather than a
  new one).
- Supplier acknowledgment (accept / accept-with-changes / reject) captured via the same
  lightweight response-link mechanism as RFQ responses in MVP; full portal self-service is
  Future Version.

**Rules / logic**
- A PO cannot be edited after `sent` except through a tracked amendment (new revision number,
  old version retained) — mirrors DMS's "never silently overwrite" versioning philosophy.
- Cancelling a PO with existing receipts/invoices against it is blocked; must be closed
  instead, to preserve the three-way-match audit trail.

## 3E. Goods Receipt (GR)

- Fields: linked PO, lines received (quantity, condition notes), receiver, received-at,
  photo attachment(s) (stored via **DMS**), barcode/QR scan input (scans the PO or item
  barcode to pre-fill the line and quantity — camera-based, no dedicated scanner hardware
  required for MVP).
- Partial receipts supported — a PO can have multiple GRs until fully received or manually
  closed short.
- Over-receipt (qty received > qty ordered) beyond a configurable tolerance flags an exception
  (3K) rather than silently accepting it.
- Discrepancy (damaged, short-shipped) captured as a note + photo, and can optionally spin off
  a Service/Helpdesk case in **CRM** against the supplier (informational `subject_type`/
  `subject_id` link, same as every other cross-module reference in this platform) — not a hard
  dependency, works fine if CRM's Helpdesk isn't in use.

## 3F. Invoice Capture & Three-Way Match

- Fields: linked PO, supplier invoice number/date, lines, amount, currency, attached invoice
  document (via **DMS**), submission channel (manual entry, or the lightweight supplier
  upload link from §2).
- **Three-way match**: PO line vs. GR line vs. Invoice line, on quantity and price, within a
  configurable tolerance (%, or flat amount) per tenant/category.
  - Full match → routes for payment approval via **WNE** (`workflow_code =
    purchase.invoice_approval`) — Purchase does not process payment itself; it hands off an
    "approved for payment" event, since AP/payment execution is Finance's domain (kept as an
    explicit open item, §5).
  - Mismatch beyond tolerance → exception (3K), routed for manual review/approval instead of
    silently blocking or silently paying.
- **Budget vs. actual note**: budget consumption is recognized at PO commitment (soft-check,
  3B), and reconciled against actual invoiced amount here — no separate budgeting engine
  needed for MVP-level visibility.

## 3G. Vendor Profile (extends CRM Partner)

- `PURCHASE.vendor_profiles`: 1:1 extension of `CRM.partners` (where the partner holds a
  `Vendor` role) — payment terms, incoterms, preferred currency, tax/registration reference,
  bank details (encrypted at rest — flagged explicitly as a security-sensitive field),
  preferred status flag, onboarding status.
- No vendor "master" duplicate of CRM data (name, address, contact points) — those stay in
  `CRM.partners`/`CRM.addresses`/`CRM.contact_points` and are simply read/joined, same
  cross-schema-FK pattern CRM's own spec already establishes for Vertical → Core reads.
- **MVP certifications/compliance**: a flat `pur_vendor_documents` list (type, doc via DMS,
  expiry date) — insurance certs, business licenses, tax certs. Expiry reminders via
  **Schedule** → **WNE**, same reminder pattern as contracts.
- **Future Version (full SRM)**: structured audit records, CAPA workflow (non-conformance →
  corrective action → verification, itself just another **WNE** workflow definition, not new
  engine code), periodic scorecards computed from GR/invoice/RFx history (on-time %, reject
  rate, responsiveness), ESG/sustainability metric fields with real scoring.

## 3H. Contract Management

- Fields: linked supplier, title, type (framework/blanket/project), value, currency,
  start/end date, auto-renewal flag + notice period, linked contract document (via **DMS**,
  which already handles versioning/retention — Purchase does not re-implement document
  handling), status (`draft → active → expiring_soon → expired → renewed → terminated`).
- **Spend-against-contract**: running total of PO/invoice amounts referencing this contract vs.
  contract value/ceiling — flat rollup query in MVP, no forecasting.
- Renewal reminder: a recurring **Schedule** item (e.g. "90/60/30 days before end_at") →
  **WNE** notification to the contract owner — same mechanism DMS uses for retention
  expiry, reused rather than reinvented a third time.
- **Future Version**: structured compliance-clause tracking (e.g. required SLA %, required
  cert on file) with automated flagging if a linked vendor's compliance doc lapses mid-contract.

## 3I. Catalog Management

- `pur_catalog_items`: item code, description, category, unit of measure, preferred supplier
  (`CRM.partners`), negotiated price, price valid-from/to, source (manual / from an awarded
  RFQ in 3C).
- PR/PO line entry can search the catalog to pre-fill price/supplier; a PR/PO line referencing
  a non-catalog item is flagged (informational, not blocking) as "off-catalog" for reporting.
- **Future Version**: punch-out catalog integration (cXML/OCI) for suppliers that expose one,
  multi-tier/contract-specific pricing, approval-required items list.

## 3J. Spend Analytics

- **MVP**: filterable views — spend by supplier, by category (direct/indirect, CAPEX/OPEX —
  set on the PR/PO line, tenant-editable lookup, not an auto-classifier), by cost center, by
  time period; spend-vs-contract rollup (from 3H); simple supplier concentration view (% of
  total spend by top N suppliers) — a query/report, not a modeling engine.
- **Future Version**: trend forecasting, category benchmarking, concentration *risk* scoring
  (not just %), and the AI-assisted anomaly/forecast features described in 3L feed richer
  views into this same dashboard once built.

## 3K. Exception Management Engine

**Purpose:** the one place every "something needs attention" signal in Purchase surfaces from
— mirrors how WNE centralizes notification delivery so no module reinvents alerting.

- Exception types (MVP): overdue approval (PR/PO/invoice sitting past an SLA), late delivery
  (GR not received by PO expected date), price variance (invoice/PO price outside tolerance),
  budget flag (PR/PO over soft budget threshold), unmatched invoice (fails three-way match).
- Each exception type fires a `WorkflowRequested` or `NotificationRequested` event into
  **WNE**, with routing/escalation rules configured in WNE like any other module — Purchase
  does not build a parallel alerting/escalation mechanism.
- Dashboard (3A) exception strip reads from a single `pur_exceptions` log (append-style, one
  row per detected exception + resolution status) rather than recomputing across tables live
  on every page load.
- **Future Version**: duplicate-PR and price-anomaly detection (3L, AI-assisted) feed into
  this same exception log/type set, not a separate alert surface.

## 3L. AI-Assisted Procurement — **Future Version**

- Built as an application on top of **AIInsights Core** (the tenant-facing "ask your data"
  Claude API feature already designed for this platform) rather than a bespoke ML stack inside
  Purchase — keeps cost/complexity centralized in one place, and inherits AIInsights' existing
  per-tenant read-only DB scoping, schema annotations, and usage-metering/entitlement model.
- **Demand forecasting**: pattern/trend query over historical PR/PO volume by category —
  presented as an AIInsights-generated summary/chart, not a standalone forecasting model
  trained per tenant.
- **Supplier recommendation**: given a new PR/RFQ line, suggest suppliers based on past
  award history, price competitiveness, and (once 3G scorecards exist) performance.
- **Price-anomaly detection**: flag a PO/invoice line whose price deviates materially from
  that item's historical price band — feeds into the Exception Engine (3K) as a new exception
  type once built, not a separate alert channel.
- **Duplicate-purchase prevention**: the MVP's simple rule-based check (3B) is the placeholder;
  the AI version generalizes it (near-duplicate descriptions, different requesters, same
  underlying need) using the same AIInsights query surface.

## 3M. ESG & Compliance Tracking

- **MVP**: document-and-flag only — `pur_vendor_documents` (3G) covers certifications/
  licenses/insurance with expiry tracking; a simple free-text/percentage `local_content_pct`
  field on PO lines where a tenant needs to report it (e.g. regulatory requirement in certain
  jurisdictions), no enforcement logic.
- **Future Version**: structured sustainability metrics (per-supplier ESG scorecard),
  local-content *calculation and enforcement* (block/warn on PO if below a tenant-configured
  threshold), regulatory document expiry escalation tied into the SRM CAPA workflow (3G).

---

# 4. Storage

**Database (schema `PURCHASE`, tenant DB — consistent with `CLAUDE.md` §7A; no `tenant_id`
column, isolation is the DB boundary, per the platform's DB-per-tenant convention — Purchase
is built clean against this rule from day one rather than inheriting WNE's current
`tenant_id`-column inconsistency, see §5 note):**

**Master / lookup tables**
- `PURCHASE.vendor_profiles` — 1:1 extension of `CRM.partners` (procurement-specific fields).
- `PURCHASE.categories` — tenant-editable spend category lookup (direct/indirect, CAPEX/OPEX).
- `PURCHASE.cost_centers` — tenant-editable cost center/department lookup.
- `PURCHASE.rfx_criteria` — Future Version: weighted evaluation criteria lookup.

**Transaction tables** (`pur_` prefix + level, matching the `sched_`/`hd_`/`svc_` convention
already established in `SCHEDULE_SPECS.md` / `CRM_SPECS.md`)
- `pur_vendor_documents` — certs/licenses/insurance, doc via DMS, expiry date.
- `pur_catalog_items` — approved items, preferred supplier, negotiated price.
- `pur_requisition_hdrs`, `pur_requisition_lines`
- `pur_rfx_hdrs`, `pur_rfx_lines`, `pur_rfx_invitations`, `pur_rfx_responses`,
  `pur_rfx_response_lines` — (Future: `pur_rfx_scorecards` for weighted evaluation)
- `pur_order_hdrs`, `pur_order_lines`, `pur_order_revisions` (amendment history)
- `pur_receipt_hdrs`, `pur_receipt_lines`
- `pur_invoice_hdrs`, `pur_invoice_lines`, `pur_invoice_matches` (three-way match results)
- `pur_contract_hdrs` (linked document via DMS, linked supplier via CRM)
- `pur_exceptions` — append-style exception log feeding 3A/3K
- `pur_budgets` — simple period × cost-center × category soft-budget figures (MVP)
- Future Version: `pur_capa_records`, `pur_audit_records`, `pur_esg_scores`,
  `pur_supplier_scorecards`

**Object file storage** (per `CLAUDE.md` §7B — new `PURCHASE/` folder per tenant, same
convention as every other module):
```text
tenant_001/PURCHASE/
├── rfx/{rfx_id}/
├── orders/{po_id}/
├── receipts/{receipt_id}/
├── invoices/{invoice_id}/
└── contracts/{contract_id}/
```
- In practice, most document *content* (PO PDFs, contracts, invoices, cert files) is stored
  and versioned through **DMS** (`owning_module = Purchase`), not duplicated here — this
  folder structure exists for the R2 bucket convention/restore-planning consistency described
  in `CLAUDE.md` §7B, mirroring how DMS itself reserves a folder per module.

# 5. Technical Notes

> All necessary technical detail to help AI Coding

**Architecture pattern:** Core module (Modular monolith, `app/Modules/Purchase/`), same shape
and posture as WNE/DMS/CRM/Schedule. Justification per `CLAUDE.md` §2: nothing here has
different scaling needs, different runtime requirements, or a standalone-reuse case strong
enough to justify extraction — it's CRUD + workflow orchestration + a matching/comparison
engine, all comfortably monolith-shaped. The one *future* candidate for extraction is punch-out
catalog integration (external protocol handling, per-supplier connectors) — flagged, not built.

**Integration points (facade + events, same seam pattern as every other Core module):**
- **CRM** — Purchase reads/depends on `CRM.partners` for the vendor record (cross-schema FK,
  Core-peer → Core direction, same rule CRM's own spec documents for itself: Purchase never
  writes into CRM's schema, only references `partner_id`). If a vendor doesn't exist yet,
  Purchase calls `PartnerService::findOrCreate(...)` rather than inserting into CRM's tables
  directly.
- **WNE** — every approval (PR, PO, invoice) and every notification (exceptions, reminders,
  supplier communications) routes through `MessagingService::requestWorkflow(...)` /
  `::notify(...)`. Purchase implements zero approval or delivery logic itself.
- **DMS** — every document (PO PDF, contract, invoice, vendor cert, RFx attachment) is stored
  via `DocumentService::upload()`/`::attach()`, inheriting DMS's versioning, retention, and
  audit trail for free instead of Purchase re-implementing any of it.
- **Schedule** — every date-driven reminder (contract renewal, cert expiry, RFx due date) is a
  calendar item created via Schedule's facade, not a bespoke cron/date-check inside Purchase.
- **AIInsights Core** — all Future Version AI features (3L) are AIInsights queries/tools
  scoped to Purchase's tables, not a separate AI integration.

**MVP scope boundary (explicit, so nothing below blocks a fast first ship):**
- RFQ only in v1 sourcing (3C); RFI/RFP + weighted scoring is additive on the same `rfx_hdrs`
  table shape (a `type` column + future `pur_rfx_scorecards` table), not a breaking change
  later.
- Supplier "portal" in v1 is a signed upload-link exchange, not authenticated supplier
  accounts — real portal login is Future Version, and the response/document tables are shaped
  so adding real supplier auth later doesn't require re-modeling RFx responses or invoice
  submissions, just adding an identity layer in front of them.
- Budget checks are soft (warn + route for extra approval) — no hard budget-enforcement engine
  in v1, per MVP bias.
- No punch-out, no AI models, no ESG scoring logic, no CAPA workflow engine in v1 — all listed
  explicitly in §2 Future Version with a stated reuse path so none of it requires a schema
  rewrite when built.
- **Payment execution is explicitly out of scope.** Purchase's job stops at "invoice matched
  and approved for payment" (an event/status), same way CRM's `subject_type`/`subject_id`
  stops at "here's the pointer, resolving it is someone else's job." A Finance/AP module (not
  yet designed) would own actual payment processing/bank integration — flagged as an open item
  below rather than guessed at here.

**Tenant isolation note:** Purchase is specified **without** a `tenant_id` column, consistent
with `CLAUDE.md` §4/§7 (DB-per-tenant is the isolation boundary). This is a deliberate choice
to build new modules clean against the documented rule rather than propagate the known WNE
inconsistency (flagged in project memory as an open reconciliation item) into a new module.

**Suggested build order for Claude Code:** 3G (vendor profile, thin extension of CRM) → 3B/3D
(PR → PO core flow, the spine everything else hangs off) → 3E (Goods Receipt, incl. barcode/
photo capture) → 3F (invoice capture + three-way match) → 3K (exception log, cheap and
high-value once 3B–3F exist) → 3I (catalog) → 3H (contracts, wire into DMS + Schedule) → 3C
(RFQ) → 3J (spend analytics views) → 3M (ESG document flags) — then revisit 3C's weighted RFx,
3G's full SRM, and 3L (AI-assisted) as Future Version once MVP has real usage.

**Marketability notes**
- Three-way match + full audit trail is a strong "why not just use email/spreadsheets" pitch
  for the same conservative legal-buyer audience CRM's spec targets — procurement discipline
  reads as institutional maturity.
- Reusing CRM's Partner registry for vendors (rather than a separate vendor master) means
  Purchase is cheap to enable for a tenant that already has CRM active, and the "one partner,
  many roles" story (a firm's landlord could also be a vendor) is a genuine differentiator.
- The lightweight supplier upload-link approach gets most of "supplier portal" marketing value
  at a fraction of the build cost — worth demoing as "supplier self-service" even before real
  portal login exists.
- Spend analytics (even the flat MVP version) is a natural upsell trigger toward AIInsights
  Core once a tenant has enough transaction history for the AI features to feel valuable.

**Open items to fill in as this module grows**
- [ ] Finance/AP module ownership — who executes payment once Purchase marks an invoice
      approved (Core module, or an external accounting-system integration)?
- [ ] Multi-currency handling depth (FX rate source, revaluation) — v1 assumes a single
      transaction currency per PO/invoice, stored as entered.
- [ ] Whether the eventual full Supplier Portal is a separate authenticated area of the same
      app, or a distinct lightweight frontend — decide once real portal usage is validated.
