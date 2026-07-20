# Accounting Module
## Financial Core — GL, AR, AP, Cash & Banks, Assets, Inventory Costing, Cost Accounting, Budgeting, Multi Company/Currency, Indonesian Tax & Compliance — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every vertical (Legal today; Property and others later) and every other Core module (CRM,
DMS, Schedule, WNE) eventually produces something with money attached to it: an invoice, a
bill, a payment, a trust deposit, an asset purchase, a payroll run. Left unsolved centrally,
this repeats the same anti-pattern WNE/DMS/CRM were built to avoid:

- Each vertical invents its own "money" fields (a balance column here, a paid-flag there) —
  no double-entry integrity, no single source of financial truth, no way to produce a real
  Balance Sheet or P&L across the whole tenant.
- No unified subledger — AR/AP end up as ad hoc tables inside whichever module got there
  first, instead of being reusable by every vertical (Legal billing, Property rent roll, a
  future Sales module) against the **same** Chart of Accounts.
- **Indonesian statutory compliance is non-negotiable and easy to get expensively wrong**:
  PPN (VAT), the PPh withholding family (21/23/26/4(2)/22/15), monthly/annual SPT filing, and
  — as of 2026 — **mandatory routing through DJP's Coretax system**, which replaced the
  legacy e-Faktur/e-Bupot/DJP Online applications and expects tax documents to reconcile with
  the taxpayer's own books in near real time. A tenant (especially a law firm handling client
  trust money) cannot be sold this product without correct tax and audit behavior baked in.
- No standalone offering — Accounting is one of the most commonly *purchased on its own*
  categories of business software; the module must work with nothing else installed, the same
  standalone requirement already applied to DMS and Schedule.
- No automation from the rest of the ERP — Legal case billing, Property rent invoices, DMS
  storage overage, Schedule-driven recurring service fees, CRM-originated sales orders all
  need to become GL entries and AR invoices **without those modules knowing anything about
  double-entry bookkeeping** — same decoupled seam as everywhere else in this codebase.

**Client requirements:**
- Full double-entry General Ledger, statutory-correct for Indonesian PSAK (Pernyataan Standar
  Akuntansi Keuangan) presentation, as the single source of financial truth.
- AR and AP subledgers that reconcile to GL control accounts automatically — never manually
  adjusted.
- Cash & Bank management with reconciliation against bank statements.
- Fixed Asset register with Indonesian tax depreciation rules (fiscal vs. commercial
  depreciation, per PMK/UU HPP asset group classification) alongside commercial (PSAK)
  depreciation.
- Inventory **costing** (valuation, COGS) — Accounting owns the money side of inventory;
  physical stock (quantities, locations, movements) is out of scope here and belongs to a
  future Inventory/Warehouse module, which will publish stock-movement events this module
  consumes.
- Basic cost accounting (cost centers, simple allocation) and budgeting (budget vs. actual)
  — enough to be sellable, not a full budgeting suite.
- Multi-company (multiple legal entities under one tenant, e.g. a law firm's operating company
  + a client trust entity) and multi-currency (foreign-currency transactions with realized/
  unrealized gain-loss), since both are common even for a single-tenant SME.
- Indonesian tax engine: PPN (output/input, faktur pajak), PPh withholding (21/23/4(2)/22/15,
  bukti potong), **Coretax-compatible export** (XML/API) since third-party bookkeeping
  software is expected to integrate with Coretax rather than route around it.
- Audit & compliance: immutable posted-journal trail, period locking, approval-gated posting
  for material entries, user-action audit log — mirrors DMS's audit posture, since financial
  records carry the same discoverability/integrity requirements.
- Recurring transactions (recurring journal templates, recurring AR/AP) and bank/account
  reconciliation as first-class engines, not bolted-on reports.
- **Automation from ERP** — other modules (Legal, CRM, Schedule, DMS) trigger GL postings and
  AR/AP documents via events/facade, never by writing to Accounting tables directly.

# 2. Goals

> Designated features solving the Backgrounds above. **MVP-first** — this is a large module by
> feature count, so scope is deliberately compressed per engine; anything not needed to invoice,
> pay, record, reconcile, and report correctly (with correct Indonesian tax handling) is pushed
> to Future Version.

## In scope for v1 (MVP — quick implementation)

> **Non-negotiable MVP constraint:** Indonesian tax and statutory-compliance behavior (PPN,
> PPh withholding, Faktur Pajak / Bukti Potong numbering, Coretax-compatible export, period/
> audit integrity) is **foundational, not an add-on**. It ships in the same first build pass
> as COA/GL and AR/AP — AR/AP are not considered "done" until they post correct tax treatment,
> because an invoice or bill issued without correct PPN/PPh handling is a compliance liability
> from the very first transaction, not a gap that's safe to patch in later. Everything else in
> this module (Assets, Inventory Costing, Cost Accounting, Budgeting, multi-company/currency
> depth, Recurring, Reconcile automation) can be legitimately phased — tax correctness cannot.

- **Chart of Accounts (COA) & GL** — configurable COA (Indonesian standard grouping: Aset,
  Liabilitas, Ekuitas, Pendapatan, HPP, Beban), manual journal entry, GL posting, trial
  balance. This is the foundation every other engine posts through — nothing bypasses it.
- **Indonesian Tax Engine (built alongside COA/GL, before AR/AP is considered usable)** — tax
  codes/rates, withholding types, Faktur Pajak and Bukti Potong numbering/generation, and the
  Coretax export driver (full detail in §3M) exist and are wired in *before* AR/AP go live for
  a real tenant — AR/AP posting logic is written against this engine from the start, not
  retrofitted onto tax-naive invoice/bill tables.
- **AR (Accounts Receivable)** — customer invoices (from Partners via CRM), payment
  application, AR aging, control-account auto-reconciliation to GL. Every invoice posts with
  correct PPN output treatment and (where applicable) Faktur Pajak generation from the first
  invoice issued — this is not an optional toggle added later.
- **AP (Accounts Payable)** — vendor bills, payment scheduling/approval, AP aging, same
  control-account auto-reconciliation. Every bill posts with correct PPh withholding
  calculation and Bukti Potong generation from the first bill entered, for the same reason.
- **Cash & Banks** — bank/cash accounts, cash-in/cash-out, inter-account transfers, bank
  statement import (CSV/MT940-style), manual + rule-assisted reconciliation.
- **Fixed Assets** — asset register, straight-line commercial depreciation, Indonesian fiscal
  depreciation (asset-group-based, declining-balance or straight-line per group per UU HPP),
  disposal, dual (commercial vs. fiscal) depreciation books.
- **Inventory Costing (interface only)** — weighted-average costing engine that consumes stock
  movement events (from a future Inventory module or manual entry) and posts COGS/inventory
  valuation journals. No physical stock management in this module.
- **Cost Accounting (lightweight)** — cost centers/dimensions on journal lines, simple
  percentage-based allocation runs (e.g. shared overhead split across cost centers).
- **Budgeting (lightweight)** — annual budget by account × cost center × period, budget vs.
  actual variance report. No rolling forecasts, no what-if scenarios in v1.
- **Multi Company** — multiple legal entities inside one tenant DB, each with its own COA
  instance, ledger, and fiscal calendar; consolidated trial balance across companies as a
  report (not automated eliminations — see Future Version).
- **Multi Currency** — foreign-currency transactions on AR/AP/journal, daily exchange rate
  table, realized gain/loss on settlement, simple period-end unrealized revaluation.
- **Indonesian Tax Engine** — PPN output/input tracking with Faktur Pajak numbering, PPh
  withholding calculation (21/23/4(2) at minimum for MVP; 22/15 as config-ready types),
  Bukti Potong generation, **Coretax export driver** (structured XML matching DJP's import
  format, since Coretax is now the mandatory channel — see §5).
- **Financial Analysis / Reporting** — Trial Balance, Balance Sheet, P&L (Laba Rugi), Cash
  Flow (indirect method), AR/AP aging, GL detail/drill-down. PSAK-compliant presentation
  format for the primary statements.
- **Audit & Compliance** — immutable posted-journal ledger (corrections via reversing entry
  only, never edit-in-place), period locking (soft-close/hard-close), approval workflow for
  postings above a configurable threshold (via WNE), full user-action audit log (mirrors DMS's
  `access_logs` pattern).
- **Recurring Transactions** — recurring journal templates and recurring AR/AP document
  templates (e.g. monthly retainer invoice, monthly rent), generated on schedule (via
  Schedule module) with a review-before-post queue.
- **Reconcile** — bank reconciliation (statement line ↔ GL cash transaction matching, manual +
  rule-assisted auto-match) and AR/AP control-account reconciliation (automatic, since
  subledger and GL post together transactionally — never a "matching problem" the way bank
  recon is).
- **Automation from ERP** — `AccountingService` facade + `JournalPostingRequested` /
  `InvoiceRequested` / `BillRequested` events so any module (Legal billing, CRM sales, DMS
  storage overage, Schedule-triggered fees) can post financial transactions without touching
  double-entry logic itself — same seam pattern as WNE/DMS/CRM.

## Future Version (explicitly deferred — do not build now)

- **Consolidation with intercompany eliminations** — v1 gives a combined trial balance across
  companies; true consolidation (ownership %, minority interest, elimination entries,
  intercompany matching) is a distinct, much heavier accounting problem — build only if a
  client with a real group structure requires it.
- **Full physical Inventory/Warehouse module** (stock, locations, transfers, stock-take) —
  Accounting only does the costing/valuation layer in v1; physical inventory is a separate
  future Core module that will publish the events this module already knows how to consume.
- **Standard costing / variance analysis** (material/labor/overhead variance) — v1 is
  weighted-average actual costing only.
- **Multi-level budget approval workflows, rolling forecasts, driver-based budgeting** — v1 is
  a flat annual budget with variance reporting.
- **Automated bank feed integration** (open banking API per-bank) — v1 imports statement files
  manually; live feed integration is a per-bank, high-maintenance integration best justified
  once a specific bank partnership exists.
- **Direct Coretax API push (real-time, per-document)** — v1 exports Coretax-compatible XML for
  bulk import (the officially supported fallback per DJP's own FAQ guidance); a live API
  integration is a candidate for a justified microservice extraction later (different
  auth/runtime lifecycle, external dependency isolation) once transaction volume justifies it.
- **PPh 21 full payroll engine** (PTKP calculation, TER rates, BPJS integration, THR) — v1
  supports PPh 21 as a withholding-type on any payment/journal line (enough to produce a
  correct Bukti Potong for professional fees, director fees, etc.); a dedicated Payroll module
  with the full employee tax engine is a separate, larger future build.
- **Multi-book / multi-GAAP parallel ledgers** (e.g. simultaneous IFRS + PSAK books) — v1 has
  one commercial book + one fiscal (tax) depreciation book, which is the actual Indonesian
  statutory requirement; a third parallel book is deferred until a client needs it.
- **E-invoicing beyond Faktur Pajak** (e.g. PEPPOL-style for export clients) — not an Indonesian
  requirement today; revisit if/when relevant.
- **Advanced financial analysis** (ratio dashboards, forecasting, anomaly detection via AI) —
  natural extension of `AIInsights Core` once that module exists; v1 ships the statutory
  reports only.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB
> design.

## 3A. Main Dashboard

**Function / features**
- Financial snapshot: cash position (all bank/cash accounts), AR total + aging summary, AP
  total + aging summary, this-period revenue/expense at a glance, budget-vs-actual headline
  variance, unposted/pending-approval journal count, upcoming recurring documents due.
- "My work" queue: journals awaiting my approval, bills awaiting my payment approval,
  unmatched bank statement lines assigned to me.
- Company switcher (multi-company) always visible in the top bar per `DESIGN.md` shell
  convention — every widget respects the selected company (or "All Companies" combined view
  where that's meaningful, e.g. cash position).

**Layout**
- Top: summary cards (Cash Position, AR Outstanding, AP Outstanding, Net Income MTD).
- Main: tabbed — "My Approvals" | "Recent Journals" | "Aging Summary" | "Upcoming Recurring".
- Every row uses the shared **Status Rail** (per `DESIGN.md`): `danger` = overdue AR/AP or
  posting error, `warning` = due soon / pending approval, `success` = settled/reconciled,
  `info` = system-generated (auto-recurring, auto-allocation), neutral = normal open item.

**Rules / logic**
- Tenant-scoped by default global scope (DB-per-tenant, per `CLAUDE.md` §4); additionally
  **company-scoped** within the tenant DB (see §5 for why `company_id` is required here even
  though `CLAUDE.md` §7 says no `tenant_id` column — this is a different, necessary axis).
- Only posted (not draft) transactions affect balances shown on the dashboard.

## 3B. Chart of Accounts & GL Setup

- **COA**: `account_code`, `account_name`, `account_type` (Asset/Liability/Equity/Revenue/
  COGS/Expense — Indonesian grouping: Aset, Liabilitas, Ekuitas, Pendapatan, Harga Pokok
  Penjualan, Beban), `normal_balance` (debit/credit), `parent_account_id` (hierarchy),
  `is_control_account` (flags AR/AP/Inventory control accounts that subledgers post through
  and that cannot receive direct manual journal lines), `is_active`, per-company (a company can
  start from a shared template COA and diverge).
- **COA template**: a starter Indonesian-standard COA ships with the module (numbering
  convention 1xxxx Aset, 2xxxx Liabilitas, 3xxxx Ekuitas, 4xxxx Pendapatan, 5xxxx HPP, 6xxxx
  Beban) so a new company isn't starting from a blank sheet — directly supports the "quick
  implementation" goal.
- **Fiscal calendar/periods**: per company, `fiscal_years` + `fiscal_periods` (usually
  calendar-month), each with a `status` (`open` / `soft_closed` / `hard_closed`).
- **Dimensions**: cost center, and optionally project/subject — configurable list, attachable
  to any journal line for cost-accounting slicing (§3I) without changing the COA itself.

**Rules / logic**
- Control accounts (`is_control_account = true`) reject direct manual journal postings — they
  can only be touched by the AR/AP/Inventory subledger engines, enforced at the service layer,
  not just UI-hidden — this is what guarantees subledger-to-GL reconciliation is automatic
  rather than a manual chase (per Backgrounds).

## 3C. General Ledger / Journal Entries

- **Manual journal entry**: header (date, company, currency, memo, source = `manual`) + N
  balanced lines (account, debit/credit, cost center, description, optional
  `subject_type`/`subject_id` polymorphic link back to whatever triggered it — same seam
  pattern as WNE/DMS/CRM).
- **Auto-generated journals**: every subledger action (AR invoice, AP bill, payment, asset
  depreciation run, inventory movement, recurring template firing, ERP automation event) posts
  through the same `JournalService::post()` — there is exactly **one** posting path in the
  whole module, manual or automatic, so GL integrity rules never have two implementations.
- **Status**: `draft → pending_approval → posted → reversed`. Never `edited-after-posted` —
  correcting a posted entry always creates a new reversing entry referencing the original
  (audit integrity, per Backgrounds/§3M).
- List view: filterable by company, period, account, cost center, status, source module.
  Status Rail per state.
- Detail/drawer: full line detail, audit trail (who created/approved/posted), linked source
  document (invoice/bill/asset/etc.) if any.

**Rules / logic**
- A journal must balance (Σdebit = Σcredit) per currency before it can leave `draft`.
- Posting into a `hard_closed` period is blocked outright; `soft_closed` allows posting only
  with elevated approval (via WNE) and logs an exception.
- Postings above a configurable tenant threshold (amount, or touching specific sensitive
  accounts) route through WNE (`WorkflowRequested`, `workflow_code = accounting.journal_approval`)
  before moving to `posted` — Accounting doesn't implement approval logic itself, same pattern
  CRM/DMS already use for WNE.

## 3D. Accounts Receivable (AR)

- **Customer invoice**: header (partner — resolved via CRM's `partners`, company, currency,
  issue/due date, PPN treatment, `subject_type`/`subject_id` back to the originating vertical
  record e.g. `legal.case_hdrs`) + line items (description, qty, price, discount, tax code).
  On posting: creates the AR control-account journal + a PPN output tax line if applicable +
  (if configured) a Faktur Pajak record (§3M).
- **Payment application**: receive payment (full/partial), apply against one or more open
  invoices (oldest-first default, manually overridable), posts to Cash/Bank + AR control.
- **Credit note**: reduces an invoice or stands alone against a partner's balance.
- **AR Aging report**: current / 1-30 / 31-60 / 61-90 / 90+ buckets, by partner, drill into
  open invoices.
- List/detail views reuse the shared Data Table + Status Rail + Comment/Activity Thread
  components per `DESIGN.md`, consistent with how CRM's Service Cases and Helpdesk look.

**Rules / logic**
- Invoice partner comes from **CRM's unified `partners` table** — Accounting never maintains
  its own customer master, same "Core reuses Core" rule already established between DMS/CRM/
  WNE. A partner needs no special "customer" role to be invoiced; any partner can receive an
  AR invoice.
- AR control-account balance is always exactly the sum of open invoice balances — enforced by
  posting invoices/payments through the same transactional service call, not reconciled after
  the fact.

## 3E. Accounts Payable (AP)

- **Vendor bill**: mirrors AR invoice structurally — header (partner via CRM, company,
  currency, due date) + lines, with PPh withholding calculated where applicable (the tenant is
  the withholding agent — e.g. PPh 23 on a professional service bill) and a Bukti Potong
  record created on posting (§3M).
- **Payment scheduling & approval**: bills queue for payment by due date; payment run (single
  or batch) routes through WNE approval above a configurable threshold before disbursement is
  posted — same approval seam as journals (§3C), reused rather than reinvented.
- **AP Aging report**: mirrors AR aging.
- **Debit note**: mirrors AR credit note.

**Rules / logic**
- AP control-account balance is always exactly the sum of open bill balances, same
  transactional-integrity rule as AR.
- Withholding tax lines reduce the amount actually paid to the vendor but do not reduce the
  gross expense recognized — both amounts are visible on the bill and the resulting Bukti
  Potong.

## 3F. Cash & Bank Management

- **Cash/bank accounts**: master list (bank name, account number — masked in UI except last 4
  digits, currency, linked GL cash account, company).
- **Cash in / cash out**: simple receipt/disbursement entries not tied to an AR/AP document
  (e.g. petty cash, bank fees, interest income).
- **Inter-account transfer**: move funds between two cash/bank accounts (including
  cross-currency, using the day's rate).
- **Bank statement import**: CSV (and common MT940-style export) upload; parsed into staged
  statement lines for reconciliation (§3Q).

**Rules / logic**
- Every cash/bank GL account is itself flagged reconcilable; unreconciled-for-N-days items can
  optionally raise a WNE notification to the account owner (config, off by default in MVP).

## 3G. Fixed Assets

- **Asset register**: acquisition (date, cost, vendor via CRM, company, asset group per
  Indonesian tax classification — Kelompok 1-4 for tangible non-building, plus Bangunan
  Permanen/Non-Permanen for buildings), useful life, commercial depreciation method
  (straight-line default; declining-balance optional), fiscal depreciation method/rate (per
  asset group, per prevailing PMK rules — configurable table, not hardcoded, since rates are
  set by regulation and can change).
- **Depreciation run**: monthly batch job posts commercial depreciation to GL; fiscal
  depreciation is tracked in a parallel schedule (used for tax reporting / SPT Tahunan
  reconciliation, not separately posted to the commercial GL — this is the standard
  commercial-vs-fiscal dual-book approach, not two ledgers).
- **Disposal**: sale/write-off, computes and posts gain/loss on disposal against both
  commercial NBV and (for the fiscal schedule) fiscal NBV.

**Rules / logic**
- Asset groups and their fiscal rates are a tenant-editable lookup table seeded with current
  Indonesian defaults at setup — never hardcoded in application logic, since tax regulations
  change and a solo dev shouldn't need a code deploy to update a depreciation rate table.
- An asset can optionally link (`subject_type`/`subject_id`) to the AP bill that created it,
  for traceability, without a hard FK (same polymorphic seam as everywhere else).

## 3H. Inventory Costing (interface engine — no physical stock)

- **Purpose**: post the *financial* side of inventory movement. Physical inventory
  (quantities, warehouses, stock-take) is explicitly out of scope — belongs to a future
  Inventory/Warehouse Core module.
- Consumes a `StockMovementOccurred` event (qty, item, unit cost source, movement type:
  purchase-receipt / sale-issue / adjustment) from whatever module tracks physical stock
  (manual entry screen provided in v1 as a stopgap until a real Inventory module exists).
- **Weighted-average costing**: maintains a moving-average unit cost per item per company;
  posts Inventory (asset) ↔ COGS/GRNI journals on each movement.
- **Valuation report**: on-hand inventory value by item/category as of any date, reconciled to
  the Inventory control account balance (same control-account discipline as AR/AP).

**Rules / logic**
- This engine is intentionally thin in v1: it is the "costing brain," not a warehouse system —
  keeps scope small while leaving the event-driven seam ready for a real Inventory module to
  plug into later without any change on the Accounting side.

## 3I. Cost Accounting

- **Cost centers**: simple flat (or one-level-hierarchy) list, tenant-editable, attachable as
  a dimension on any journal/AR/AP/asset line (§3B).
- **Allocation runs**: define a rule (source account/cost center → target cost centers +
  percentage split), run monthly to redistribute shared costs (e.g. office rent split across
  case teams) — posts a journal, never mutates the original entry.

**Rules / logic**
- Allocation is percentage-based only in v1 (no activity-based/driver costing) — matches the
  "quick implementation" bias; the dimension model on journal lines is flexible enough that a
  smarter allocation engine can be added later without a schema change.

## 3J. Budgeting

- **Budget**: per company, fiscal year, account × cost center × period (monthly), amount.
  Entered via a spreadsheet-like grid (bulk paste-friendly) or CSV import.
- **Budget vs. Actual report**: variance (amount + %) by account/cost center/period, drill
  into actual GL detail from any variance line.

**Rules / logic**
- One flat annual budget version per fiscal year in v1 — no revision/scenario versioning (that
  is Future Version, per §2), keeping the schema and UI both simple.

## 3K. Multi Company

- **Companies**: master list within the tenant DB — legal name, NPWP (Indonesian tax ID),
  address, base currency, fiscal year start month, active COA template reference.
- **Company switcher**: global context selector (top bar, per `DESIGN.md` shell) scopes every
  screen in this module; a user can have access to one or several companies.
- **Combined reporting**: trial balance / P&L / balance sheet can be run "combined" across
  selected companies (simple summation by matching account code) — explicitly *not*
  consolidation with eliminations (Future Version, §2).

**Rules / logic**
- Every Accounting table carries `company_id` (see §5 for the architectural rationale). Every
  other module that posts into Accounting (Legal, CRM, DMS, Schedule) must specify which
  company a transaction belongs to — usually resolved from tenant-level default company
  configuration, overridable per transaction.

## 3L. Multi Currency

- **Currencies & rates**: ISO currency list (tenant enables the ones it uses), daily
  `exchange_rates` table (rate to base currency, effective date, source = manual entry or
  future rate-feed driver).
- **Foreign-currency transactions**: AR/AP/journal lines can be entered in a foreign currency;
  stored at both transaction-currency amount and base-currency-equivalent (rate at transaction
  date).
- **Realized gain/loss**: computed and posted automatically when a foreign-currency invoice/
  bill is settled at a different rate than it was booked.
- **Unrealized revaluation**: period-end batch job revalues open foreign-currency AR/AP/cash
  balances to the period-end rate, posts an unrealized gain/loss journal (auto-reversed at the
  start of the next period, standard practice).

**Rules / logic**
- Base currency is fixed per company at setup (matches its statutory reporting currency —
  IDR for an Indonesian entity); all financial statements report in base currency, with
  transaction-currency shown as supplementary detail on source documents.

## 3M. Indonesian Tax Engine

**PPN (Value-Added Tax)**
- Tax codes (`tax_codes`): rate, type (output/input), account mapping — configurable so a rate
  change is a data edit, not a deploy.
- **Faktur Pajak** (VAT invoice) generated on posting a taxable AR invoice: sequential
  numbering per company (Nomor Seri Faktur Pajak block, tenant-entered from their DJP
  allocation), buyer NPWP/NIK, tax base, PPN amount.
- **Input PPN** captured on AP bills (creditable against output PPN in the monthly PPN
  return/SPT Masa PPN).

**PPh (Withholding Tax)**
- Withholding types configurable, MVP covers PPh 23 (services), PPh 4(2) (final, e.g. rent),
  PPh 21 (non-payroll: professional/director fees) — modeled generically as
  `withholding_types` (code, rate, is_final) so adding PPh 22/15 later is config, not code.
- **Bukti Potong** generated on posting a bill/payment subject to withholding — one record per
  withholding event, matching the structure DJP's e-Bupot module expects (BP21/BP26/BP23/
  BP4(2)/BPU per the current Coretax e-Bupot classification).

**Coretax integration**
- Since 2026, DJP requires tax administration (Faktur Pajak, Bukti Potong, SPT filing) to be
  done **through Coretax** — the legacy e-Faktur/e-Bupot/DJP Online applications have been
  retired. Coretax officially supports third-party bookkeeping software via **structured XML
  import** (the same fallback path DJP itself points taxpayers to when direct entry fails), so
  v1 targets that integration surface rather than a live API.
- **`CoretaxExportDriver`**: generates DJP-compatible XML batches for Faktur Pajak Keluaran
  (output), Faktur Pajak Masukan (input, for reconciliation), and Bukti Potong (PPh
  Unifikasi/21), on demand or per tax period — downloaded and imported into Coretax by the
  tenant's tax preparer. Built behind a driver interface (mirrors `ChannelDriverInterface` /
  `ConferenceDriverInterface`) so a future direct-API driver is additive, not a rewrite.
- **Tax period register**: PPN and PPh obligations tracked per company per period (masa
  pajak), with due-date reminders via WNE (SPT Masa PPN: end of following month; PPh
  withholding remittance: 10th of following month; both configurable since due-date rules can
  change).

**Rules / logic**
- Tax rates, withholding rates, and due-date rules live in tenant-editable lookup tables,
  never hardcoded — regulation changes (as have happened before, e.g. PPN rate changes) must
  never require a code deploy.
- Every Faktur Pajak and Bukti Potong is immutable once issued — corrections happen via a
  replacement/cancellation record referencing the original, mirroring Coretax's own
  replace/cancel model (per DJP FAQ) rather than in-place edits.

## 3N. Financial Analysis / Reporting

- **Trial Balance**: by company, by period, with drill-down to GL detail.
- **Balance Sheet** (Neraca) and **P&L** (Laporan Laba Rugi): PSAK-standard presentation
  grouping, current period + comparative prior period, single company or combined.
- **Cash Flow Statement**: indirect method, derived from Balance Sheet movement + P&L (no
  separate cash-flow data entry needed).
- **AR/AP Aging**, **Budget vs. Actual**, **Inventory Valuation**: reuse the respective
  engines' reports (§3D/E/H/J) surfaced here as a unified reporting hub.
- All reports exportable (PDF via the existing `pdf` skill pattern / Excel via `xlsx`), since
  statutory reports routinely need to leave the app (auditor, tax preparer, bank).

**Rules / logic**
- Every statutory report (Balance Sheet, P&L) is generated **only** from posted GL data — no
  report ever reflects a draft/unapproved journal, which is what makes the numbers trustworthy
  enough for the "trust, precision" brief in `DESIGN.md`.

## 3O. Audit & Compliance

- `acct.audit_logs`: append-only, one row per action (`journal_created`, `journal_posted`,
  `journal_reversed`, `period_closed`, `period_reopened`, `invoice_posted`, `bill_posted`,
  `payment_posted`, `tax_document_issued`, `tax_document_cancelled`, `master_data_changed`),
  actor, timestamp, before/after snapshot for master-data changes — same immutable pattern as
  `dms.access_logs`.
- **Period locking**: soft-close (blocks ordinary posting, allows elevated-approval exception)
  and hard-close (blocks all posting into that period, including exceptions — only reversible
  by explicitly reopening, itself an audited, approval-gated action).
- **Approval workflow** for postings/payments above threshold, reused from WNE (§3C/E) — audit
  log captures the WNE approval chain reference, not a duplicate approval record.

**Rules / logic**
- No update/delete permitted on `acct.audit_logs` or on any posted journal/tax document at the
  app layer — matches DMS's audit-integrity rule; this is a compliance requirement, not a
  style preference, for both PSAK audit trail expectations and Coretax's own
  replace-not-edit model.

## 3P. Recurring Transactions

- **Recurring journal template**: header + line pattern, `recurrence_rule` (RRULE, reusing the
  Schedule module's recurrence approach and library — `simshaun/recurr` — rather than a second
  implementation), next-run date.
- **Recurring AR/AP template**: same pattern for invoices/bills (e.g. monthly retainer, monthly
  office rent bill).
- **Generation queue**: scheduled job (fires via Schedule module's `schedule.item_due_soon`
  pattern, or a simple internal cron if Schedule isn't installed for a standalone-only tenant)
  creates a **draft** document/journal from the template — never auto-posts — so a human
  reviews before it hits the GL, per the same "no silent auto-apply" discipline DMS uses for
  auto-tagging.

**Rules / logic**
- If the Schedule module is enabled for the tenant, recurring templates optionally show up on
  the shared calendar (via `subject_type`/`subject_id`) so "when is the next retainer invoice"
  is visible in one place — but Accounting must function standalone if Schedule is absent,
  same feature-flag-independence rule Schedule itself follows with WNE.

## 3Q. Reconcile

- **Bank reconciliation**: match imported statement lines (§3F) against GL cash-account
  transactions — auto-match by amount + date-proximity + reference-string similarity first,
  manual match for the remainder, with a running reconciled-vs-book-balance display (classic
  bank-rec worksheet).
- **AR/AP control reconciliation**: not a matching problem in this design (see §3D/E) — this
  screen is a verification report only (control account balance = sum of open items), useful
  as a trust/audit check, not a manual reconciliation task.

**Rules / logic**
- An unmatched statement line older than a configurable threshold can raise a WNE notification
  to the account owner — same reuse-WNE-don't-build-a-parallel-path rule as DMS retention and
  CRM SLA breach.

## 3R. Automation from ERP

- **`AccountingService` facade** (preferred, same-process): `postJournal(...)`,
  `createInvoice(...)`, `createBill(...)`, `recordPayment(...)`, `getAccountBalance(...)` —
  for any Core or Vertical module that needs to touch financials without knowing double-entry
  rules.
- **Event bus** (decoupled, preferred for cross-module triggers): `JournalPostingRequested`,
  `InvoiceRequested`, `BillRequested`, `PaymentRequested` — e.g. Legal fires
  `InvoiceRequested` when a case's billable time/disbursements are ready to invoice; CRM's
  `ServiceCaseSLABreached`-style events could similarly trigger a credit memo workflow;
  Schedule-driven recurring fees fire the same event a manual invoice would.
- **Consumed events**: `StockMovementOccurred` (§3H, from a future Inventory module),
  `LeadConverted`/`PartnerCreated` (from CRM, to resolve AR/AP partner references), any
  vertical module's "billable event" (e.g. `legal.case_closed_for_billing`).

**Rules / logic**
- Accounting never reaches into a vertical module's schema to compute what to bill — the
  triggering module resolves its own billable amount/description and hands Accounting a
  fully-formed request; Accounting's only job is correct financial recording, same
  one-way-dependency rule (Vertical/Core → Accounting, never reverse) as everywhere else in
  this codebase.

---

# 4. Storage

> Tables and object files used by this module. Schema: `ACCOUNTING` (per tenant DB, per
> `CLAUDE.md` §7). Naming: master tables single word; transaction tables prefixed by domain
> (`gl_`, `ar_`, `ap_`, `fa_`, `tax_`, ...), matching the convention used in the other Core
> module specs.

**Setup / master tables**
- `ACCOUNTING.companies` — legal entity master (name, NPWP, base_currency, fiscal_year_start).
- `ACCOUNTING.accounts` — Chart of Accounts (per company), `is_control_account` flag.
- `ACCOUNTING.fiscal_years`, `ACCOUNTING.fiscal_periods` — per company, with `status`.
- `ACCOUNTING.cost_centers` — dimension lookup.
- `ACCOUNTING.currencies`, `ACCOUNTING.exchange_rates`.
- `ACCOUNTING.tax_codes` (PPN), `ACCOUNTING.withholding_types` (PPh family).
- `ACCOUNTING.asset_groups` — Indonesian fiscal depreciation classification + rates.
- `ACCOUNTING.bank_accounts` — cash/bank master, linked GL account.

**GL transaction tables**
- `ACCOUNTING.gl_journals` — header (company, period, currency, status, source module,
  `subject_type`/`subject_id`).
- `ACCOUNTING.gl_journal_lines` — account, debit/credit, cost center, currency amount +
  base-currency amount.

**AR**
- `ACCOUNTING.ar_invoices`, `ACCOUNTING.ar_invoice_lines`, `ACCOUNTING.ar_payments`,
  `ACCOUNTING.ar_payment_applications`, `ACCOUNTING.ar_credit_notes`.

**AP**
- `ACCOUNTING.ap_bills`, `ACCOUNTING.ap_bill_lines`, `ACCOUNTING.ap_payments`,
  `ACCOUNTING.ap_payment_applications`, `ACCOUNTING.ap_debit_notes`.

**Cash & Bank**
- `ACCOUNTING.cash_transactions`, `ACCOUNTING.bank_statement_imports`,
  `ACCOUNTING.bank_statement_lines`, `ACCOUNTING.bank_reconciliations`,
  `ACCOUNTING.bank_reconciliation_matches`.

**Fixed Assets**
- `ACCOUNTING.fa_assets`, `ACCOUNTING.fa_depreciation_schedule_commercial`,
  `ACCOUNTING.fa_depreciation_schedule_fiscal`, `ACCOUNTING.fa_disposals`.

**Inventory Costing**
- `ACCOUNTING.inv_items` (financial shadow record — code, name, costing method, current
  average cost; physical detail owned elsewhere), `ACCOUNTING.inv_movements`,
  `ACCOUNTING.inv_valuation_snapshots`.

**Cost Accounting / Budgeting**
- `ACCOUNTING.cost_allocation_rules`, `ACCOUNTING.cost_allocation_runs`.
- `ACCOUNTING.budgets`, `ACCOUNTING.budget_lines`.

**Indonesian Tax**
- `ACCOUNTING.tax_faktur_pajak` (output + input), `ACCOUNTING.tax_bukti_potong`,
  `ACCOUNTING.tax_periods` (per company, per obligation type, filing status),
  `ACCOUNTING.tax_coretax_export_batches` (log of generated XML batches, for traceability).

**Recurring**
- `ACCOUNTING.recurring_journal_templates`, `ACCOUNTING.recurring_ar_templates`,
  `ACCOUNTING.recurring_ap_templates`, `ACCOUNTING.recurring_generation_log`.

**Audit**
- `ACCOUNTING.audit_logs` — append-only, no update/delete at app layer.

**Object files** (per `CLAUDE.md` §7B):
```text
tenant_001/ACCOUNTING/
├── {company_id}/bank_statements/{yyyy}/{mm}/
├── {company_id}/tax_documents/{yyyy}/{mm}/       # generated Faktur Pajak / Bukti Potong PDFs, Coretax XML batches
└── {company_id}/reports/{yyyy}/{mm}/             # exported PDF/XLSX statutory reports
```
- Same shared Cloudflare R2 bucket, tenant-prefixed keys, as every other module. Documents
  attached to a specific AR/AP/journal record can instead be routed through **DMS** (reuse,
  don't rebuild) via `subject_type = 'accounting.ar_invoices'` — Accounting only needs its own
  object storage for system-generated artifacts (statements, tax exports, reports), not for
  general document attachment.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, same monolithic-modular posture as WNE/DMS/CRM/
Schedule. Exposes:
- **Internal facade/service** (preferred) — `AccountingService`, `JournalService`,
  `ARService`, `APService`, `TaxService`, `AssetService` — the integration point for other
  Core/Vertical modules.
- **Internal event bus** — publishes `JournalPosted`, `InvoicePosted`, `BillPosted`,
  `PaymentRecorded`, `PeriodClosed`, `TaxDocumentIssued`; consumes `JournalPostingRequested`,
  `InvoiceRequested`, `BillRequested`, `StockMovementOccurred`, `PartnerCreated` (from CRM).
  Same one-way Vertical/Core → Accounting rule as everywhere else — Accounting never reaches
  into a calling module's schema.
- **Cross-schema FK, Core-to-Core**: Accounting's `ar_invoices`/`ap_bills` FK directly into
  `CRM.partners.id`, the same allowed direction CRM already documents for other Core modules
  (Core depending on Core is fine; the forbidden direction is Core depending on Vertical).
- **Reuse, don't rebuild**: approvals reuse WNE (never a parallel approval engine), recurrence
  reuses Schedule's `simshaun/recurr` approach, document attachment reuses DMS, notifications
  (due dates, unmatched items, tax deadlines) reuse WNE's routing — Accounting is the fifth
  Core module and should feel structurally identical to the first four, not a special case.

**On `company_id` vs. `CLAUDE.md` §4/§7's "no `tenant_id` column" rule:** these are different
axes and both are correct simultaneously. Tenant isolation is the *database* boundary (one DB
per tenant, per §4) — that rule is about **cross-tenant** isolation and stays intact; no
Accounting table needs a `tenant_id` column for that reason. **Multi-company** is an
*intra-tenant* concept — one law firm's single tenant DB legitimately contains two legal
entities (e.g. the operating company and a client-trust entity) that must never have their
ledgers mixed. That requires a `company_id` column on every Accounting transaction table, same
as it would in any single-tenant accounting system with multi-entity support. This is
unrelated to the existing WNE `tenant_id` inconsistency Simon has flagged for reconciliation —
that one is a genuine deviation from the DB-per-tenant rule and should be fixed to match
`CLAUDE.md`; `company_id` here is not a deviation, it's a normal multi-entity feature and
should be kept as designed.

**MVP scope boundary (be explicit about what's deferred):**
- Fiscal depreciation rate table is seeded with current defaults but is tenant-editable data,
  not hardcoded logic — regulation changes are a data edit.
- Coretax integration targets XML export for import into Coretax (the supported fallback path
  per DJP's own guidance for taxpayers/third-party software), not a live real-time API —
  matches the "quick implementation" bias and avoids building against an external API surface
  that (per current reporting) is still stabilizing; a live API driver is additive later behind
  the same `CoretaxExportDriver` interface.
- No consolidation/eliminations, no physical inventory, no full payroll PPh 21 engine — all
  explicitly Future Version (§2) — building the fuller version now would cost real schema
  complexity (intercompany elimination entries, warehouse/location modeling, PTKP/TER payroll
  tables) that isn't needed to ship a sellable v1.

**Suggested build order for Claude Code:** 3B (COA/GL setup) → 3C (journal engine — the single
posting path everything else depends on) → **3M (Indonesian tax engine — tax codes,
withholding types, Faktur Pajak/Bukti Potong numbering, Coretax export driver) built next,
before any AR/AP screen is considered feature-complete** → 3D/3E (AR/AP, written against the
tax engine from the start — an invoice/bill screen that doesn't yet call tax logic is treated
as unfinished, not as a shippable interim state) → 3K/3L (multi-company/multi-currency,
validated early since retrofitting `company_id` after data exists is painful, and both PPN/PPh
rules are already company-scoped) → 3F (Cash & Banks) → 3Q (bank reconciliation) → 3G (Fixed
Assets, including fiscal depreciation) → 3N (Financial Analysis/Reporting, since it depends on
everything above being correct) → 3O (Audit & Compliance hardening) → 3P (Recurring) → 3I/3J
(Cost Accounting/Budgeting) → 3H (Inventory Costing interface) → 3R (Automation from ERP
facade/events, wired incrementally as each consuming module — Legal — is ready to call it).

**Marketability notes**
- Indonesian-compliant tax handling (Coretax-ready export, correct PPN/PPh treatment) is a
  genuine differentiator against generic international accounting SaaS for the Legal vertical
  launch market — worth surfacing explicitly in sales conversations, not just an internal
  checkbox.
- Multi-company support at MVP (rather than deferred) is a real ask for professional-services
  clients (law firms commonly run a trust/client-funds entity separately from the operating
  company) — matches the Legal vertical's actual structure, not a speculative generalization.
- Standalone sellability (per Backgrounds) means Accounting can be a revenue line on its own,
  same positioning already established for DMS and Schedule.
