# Legal Module
## Notary & PPAT Practice Management — Vertical Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

This is the **first vertical module** (`CLAUDE.md` §5) — the first paid, rentable product,
built on top of the four Core modules already specced (WNE, DMS, CRM, Schedule). In Indonesia,
most Notaris also hold a dual appointment as **PPAT** (Pejabat Pembuat Akta Tanah), so a single
practitioner runs two overlapping but legally distinct practices side by side: general notarial
acts (governed by UU No. 30/2004 jo. UU No. 2/2014 tentang Jabatan Notaris) and land-conveyancing
acts (governed by PP No. 24/1997 and the PPAT regulations under Kementerian ATR/BPN). Left to
generic practice-management or document tools, this dual nature is exactly what gets lost:

- Generic case-management tools have no concept of a **Notary Protocol** — the legally mandated,
  append-only, state-archival record (minuta akta, repertorium, buku daftar legalisasi, buku
  daftar waarmerking, buku daftar wasiat, buku daftar protes) that a Notaris is personally
  liable for and must hand over intact to a successor or the Majelis Pengawas Daerah (MPD) on
  retirement. Getting this wrong is a professional-conduct risk, not just a UX inconvenience.
- PPAT land transactions have a **hard regulatory sequence** — Land Due Diligence (sertifikat
  check at Kantor Pertanahan) → Tax (PPh Final 2.5% seller + BPHTB 5% buyer, both must clear
  DJP/Bapenda **before** the deed can be signed) → Deed signing → BPN Registration (balik nama)
  — and since 2016 the DJP↔BPN tax validation is a **host-to-host electronic check**: a PPAT
  cannot even complete the deed if the system flags unpaid tax. A generic tool has no notion of
  this gate, so a firm either builds a manual checklist (error-prone) or does nothing.
- **Wasiat (wills)** carry a separate statutory obligation: every will must be reported to the
  **Daftar Pusat Wasiat (DPW)** via the AHU Kemenkumham system — a step that's easy to forget
  and has no natural home in a generic document tool.
- **Legalisasi** and **Waarmerking** are legally distinct services (the former: notary witnesses
  signing and confirms identity/content; the latter: notary merely registers a document already
  signed) but both require their own sequentially numbered ledger book — generic e-signature or
  document tools conflate them or ignore the ledger requirement entirely.
- Field work is unavoidable in this practice — a PPAT (or, more often, a field
  clerk/*asisten lapangan*) must physically visit Kantor Pertanahan to check certificates, pick
  up completed registrations, or verify a site before a due-diligence sign-off — and that work
  currently lives on paper or in someone's personal notes, disconnected from the matter file.
- Client/party data (penghadap, parties to a deed) is currently re-entered per deed with no
  link to a firm-wide contact registry, no dedupe, and no history of "which deeds has this
  person appeared in."

**Client requirements:**
- Must run as its own sellable line item — a standalone Notaris or PPAT practice can adopt
  Legal without buying anything else — but integrates cleanly with CRM (client/party registry),
  DMS (every deed and supporting document), Schedule (signing appointments, field visits, tax/
  registration deadlines), and WNE (deadline reminders, internal review/approval before
  signing) when those modules are present, exactly like Schedule and DMS already do for WNE.
- Must reflect the real regulatory sequence for PPAT deeds (due diligence → tax clearance →
  signing → BPN registration), not just be a flat document repository.
- Must support the Notary Protocol's statutory record-keeping (sequential, append-only,
  handover-able) as a first-class concept, not an afterthought.
- Must support Indonesian tax mechanics (PPh Final Pasal 4(2), BPHTB net of NPOPTKP,
  Coretax/SSPD billing-code tracking) accurately enough to drive a checklist and block signing
  on unpaid tax — **without** attempting to become a tax-filing system (DJP/Bapenda portals
  remain the system of record; Legal tracks status and evidence, it does not file on the
  practitioner's behalf).
- Must support field operators on mobile — genuinely useful away from a desk (site visits,
  BPN office runs), including photo capture and GPS-tagged visit logs.

# 2. Goals

> Designated features. MVP-first, matching the "closest to revenue, prioritize correctness and
> UX polish" guidance in `CLAUDE.md` §5.

**MVP (ship as the first paid vertical)**
- **Unified Deed model** spanning both practices (`LEGAL.deeds`), with a `deed_type` lookup
  distinguishing Notary vs. PPAT acts and driving which downstream engines apply (tax + BPN
  registration only for PPAT land deeds; protocol ledger numbering for all).
- **Matter/engagement wrapper** (`LEGAL.matters`) grouping related deeds for one client
  transaction (e.g., a property purchase = due diligence + AJB + tax + BPN registration under
  one matter) — this is the unit a client actually thinks in terms of, and the unit field
  visits and deadlines attach to.
- **Notarial Deeds** (general acts — Akta Perjanjian, Akta Kuasa, Akta Pendirian Badan Usaha,
  etc.) as a deed-type family under the unified model, with type-specific fields via
  `CUSTOMFIELDS` (per the same pattern DMS/CRM already use) rather than a table per deed type.
- **Wasiat (Wills)** — deed-type family plus a dedicated DPW registration record and status
  (drafted → registered with DPW → active → opened/executed → revoked).
- **Legalization & Waarmerking** — lighter-weight than a full deed: attach the private document
  (via DMS), record the act, and assign the correct sequential ledger number in the relevant
  protocol book — legally distinct book per act type.
- **Notary Protocol** — the ledger-of-ledgers: `protocol_books` (repertorium, legalisasi,
  waarmerking, protes, daftar wasiat, lain-lain) with append-only `protocol_entries`, yearly
  volume tracking, and a handover workflow (to successor notary or MPD) at retirement/closure.
- **AJB, Hibah, Other PPAT Deeds** — unified under the same Deed model with `category = ppat`
  and a `deed_type` covering the eight statutory PPAT act types (Jual Beli, Hibah, Tukar
  Menukar, Pemasukan ke Perusahaan/Inbreng, Pembagian Hak Bersama, Pemberian Hak Tanggungan/
  APHT, Pemberian HGB/Hak Pakai atas Tanah Hak Milik, Pelepasan Hak).
- **Land Object registry** (`LEGAL.land_objects`) — reusable across due diligence, deeds, and
  future matters on the same parcel, so a firm builds an asset history per certificate over
  time instead of re-entering it per transaction.
- **Land Due Diligence** — structured checklist per parcel: sertifikat validity (SKPT check),
  PBB payment status, blokir/sengketa (encumbrance/dispute) check, Zona Nilai Tanah reference —
  each item logged with evidence (DMS attachment) and outcome.
- **Tax tracking engine** — PPh Final (seller, 2.5% of gross transfer value or NJOP, whichever
  higher) and BPHTB (buyer, 5% net of NPOPTKP, which is tenant-configurable since it's set per
  local government) as trackable obligations per deed: base amount, computed amount, billing
  code (Kode Billing/Coretax), NTPN proof, status. **Signing is blocked in-app** until both are
  marked `paid_and_validated` for a PPAT land deed requiring them — mirrors the real
  DJP↔BPN host-to-host gate, as a workflow safeguard, not a tax filing.
- **BPN Registration tracking** — post-signing submission log (balik nama, APHT/HT-el
  registration, split/merge, etc.) with tracking number, PNBP fee, status, and the resulting
  document (new certificate) attached via DMS. Since BPN has no public integration API for a
  solo-dev-scale firm, this is a **tracked checklist + status log**, not a live system
  integration — an explicit MVP scope boundary (see §5).
- **Field Operations (mobile)** — visit scheduling (via Schedule), a lightweight mobile-first
  flow for field operators: check in with GPS-tagged location, capture photos/scans directly
  to DMS, update due-diligence or BPN-submission status from the field, all on a simple
  checklist UI. This is the one place in Legal that genuinely needs an API-backed mobile client
  rather than a responsive web page — see §5 for why this is treated as a justified exception.
- **Party/appearer management** — every deed's parties (`penghadap`, `pihak`, `saksi`, `kuasa`,
  `ahli_waris`) link to `CRM.partners` (cross-schema FK, Vertical→Core, same direction rule as
  every other module), with **identity snapshotting** at signing time (see §5 — a signed deed's
  content, including party identity details, must never silently change even if the underlying
  CRM record is later edited).

**Future Version (post-launch)**
- E-meterai (electronic stamp duty) integration at signing.
- Direct API integration with Kantor Pertanahan / Sistem host-to-host DJP-BPN, if/when BPN
  exposes anything beyond portal access at a scale worth building against.
- AHU Kemenkumham API integration for DPW will registration and corporate-deed filings
  (currently manual portal entry, tracked as a checklist item only).
- Client self-service portal (status of their matter, document requests) — reuses DMS/CRM,
  not a new stack.
- Billing/invoicing tied to matters and deed types — when a matter/case is ready to invoice,
  Legal calls **Sales**'s generic billable-request entry point,
  `SalesOrderService::createFromExternalRequest(...)` (preferred) or the `SalesOrderRequested`
  event (`SALES_SPECS.md` §3I/§5), with the matter/deed reference, billable time/disbursement
  line items, and amounts Legal has already computed. Sales creates a Sales Order
  (`subject_type = 'legal.matters'` or `'legal.deeds'`, reusing the same polymorphic seam
  `SALES_SPECS.md` §3F already defines) and, once confirmed, fires the ordinary
  `InvoiceRequested` into **Accounting** (`ACCOUNTING_SPECS.md` §3D/§3R) through its normal
  Billing Engine path. Legal never calls Accounting directly and never owns billing logic
  itself — Sales is the platform's one AR-orchestrating module (per
  `ACCOUNTING_SPECS.md` §3R), and this is the concrete path Legal uses once built. (Supersedes
  the earlier note pointing at `CLAUDE.md` §11 open items — that item is resolved.)
- Digital/e-signature capture for legalization workflows where regulation permits.
- OCR of scanned certificates and KTPs to pre-fill land object / party data (depends on DMS's
  own OCR Future Version, §3G of `DMS_SPECS.md`).

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard

**Function / features**
- Practice-health snapshot: open matters by type (Notary/PPAT), deeds pending signature, tax
  obligations pending clearance, BPN submissions in process, upcoming protocol book closures.
- "My work" queue: matters/deeds assigned to me, field visits assigned to me — unified with
  CRM's "My work" pattern (3A of `CRM_SPECS.md`).
- Deadline-risk surface: tax payment due dates, DPW registration lag, BPN submission age —
  each using the shared **Status Rail** (`DESIGN.md`) colored by urgency, the same visual
  language as Scheduler/Workflows/CRM.

**Layout**
- Top: summary cards — Open Matters, Deeds Pending Signature, Tax Pending Clearance, BPN In
  Process.
- Main: tabbed table — "My Matters" | "My Deeds" | "Field Visits" | "Protocol Books".
- Row click opens a drawer with matter/deed detail, timeline, and linked documents (DMS).

**Rules / logic**
- Tenant-scoped by database isolation (no `tenant_id` column, per `CLAUDE.md` §4/§7 ).
- A PPAT deed with unpaid/unvalidated tax surfaces with a `danger` rail regardless of sort —
  same "breach surfaces first" pattern as CRM SLA breaches.

## 3B. Matters (Engagements)

**Purpose:** the client-facing unit of work; groups one or more deeds under one transaction.

- Fields: title, matter type (free lookup — "Property Purchase," "Company Incorporation,"
  "Estate Planning," ...), primary client (`partner_id`, FK `CRM.partners`), additional related
  parties, assigned notary/PPAT (internal user), status (open/in_progress/on_hold/closed),
  opened date, target close date, notes.
- Optional origin link back to a CRM Lead (`converted_from_lead_id`) — a consultation that
  became an engagement, reusing CRM's Lead conversion flow (3D of `CRM_SPECS.md`) rather than
  building a second intake pipeline.
- Detail view: tabs — Overview, Deeds (all deeds under this matter), Documents (DMS, scoped to
  this matter via `subject_type`/`subject_id`), Field Visits, Activity Timeline.

**Rules / logic**
- Closing a matter does not require every deed to be in a terminal state (a matter can close
  with a follow-up task tracked in Schedule instead) — avoids forcing artificial completeness.

## 3C. Notarial Deeds (Entry)

**Purpose:** general notarial acts — the broad "Akta Umum" family (agreements, powers of
attorney, corporate deeds, acknowledgment of debt, etc.).

- Fields: matter (optional — some deeds are standalone, not part of a larger matter),
  `deed_type_id` (lookup, extensible — see §4), deed number (assigned on signing, per the
  active `protocol_books` volume for that year), signing date, parties (see 3J), summary/
  subject, minuta reference, custom fields per `deed_type` via `CUSTOMFIELDS`.
- Lifecycle: `draft → ready_for_signing → signed → archived`. Signing is the point the deed
  becomes immutable (content and party-identity snapshots lock; further changes require a new
  amending deed, never an edit to the original — matches real notarial practice).
- On `signed`: assigns the next sequence number in the active `repertorium` protocol book,
  fires `LegalDeedSigned` event (WNE can route this to "notify client," "notify field ops for
  next step," etc., same decoupled pattern as every other module → WNE integration).

## 3D. Wasiat (Wills)

**Purpose:** wills, with the statutory Daftar Pusat Wasiat obligation front and center.

- Extends the Deed model (`category = notary`, `deed_type = wasiat`) with a dedicated
  `LEGAL.wills` record: testator (`partner_id`), DPW registration number, DPW registered date,
  status (`drafted → dpw_registered → active → opened / revoked`).
- Dashboard flag: any will signed but not yet marked `dpw_registered` past a configurable grace
  period surfaces with a `warning`/`danger` rail — this is the single highest-liability gap in
  will practice (an unregistered will is effectively invisible to the state system), so it gets
  explicit, persistent visibility rather than being buried in a generic task list.
- Revoking or "opening" (executing) a will is logged, never a silent status flip — feeds the
  deed's immutable audit trail.

## 3E. Legalization & Waarmerking (Entry)

**Purpose:** the two lighter-weight, high-volume notarial services — legally distinct, both
requiring their own sequential ledger.

- Fields: act type (`legalisasi` / `waarmerking`), the underlying private document (attached
  via DMS, not re-authored — the notary is certifying/registering someone else's document, not
  drafting one), party/parties, date, notes.
- **Legalisasi**: notary confirms identity of signer(s) and witnesses the signing (or
  acknowledges a signature already made in the notary's presence) — requires party identity
  capture, same snapshot rule as full deeds.
- **Waarmerking**: notary registers a document already signed elsewhere, recording only that it
  existed on a given date — lighter party requirement (registrant only, not full identity
  verification of every signer).
- On completion, assigns the next sequence number in the corresponding protocol book
  (`buku_daftar_legalisasi` or `buku_daftar_waarmerking`) — same numbering mechanism as 3C/3F,
  just a different book.

## 3F. Notary Protocol (Engine)

**Purpose:** the statutory record-of-records a Notaris is personally liable for.

- `protocol_books`: one row per book type × year × volume (`repertorium`, `legalisasi`,
  `waarmerking`, `protes`, `daftar_wasiat`, `lain_lain`), status (`active`/`closed`/
  `handed_over`), notary (internal user), opened date, closed date.
- `protocol_entries`: append-only ledger rows — `book_id`, `deed_id` (or will/legalization/
  waarmerking reference), sequence number (assigned atomically, gap-free within a book+year),
  entry date. **No update or delete permitted at the app layer** — same audit-integrity rule
  as DMS's `access_logs` (3I of `DMS_SPECS.md`).
- **Handover workflow**: at year-close, retirement, or transfer, a book moves to `handed_over`
  with recipient (successor notary or MPD), date, and a generated handover manifest (PDF, via
  the same document-generation approach used for deed exports) — this is a real professional-
  conduct requirement (UU 2/2014), not a nice-to-have, so it's modeled explicitly rather than
  left as "just export a report."

## 3G. AJB, Hibah & Other PPAT Deeds (Entry)

**Purpose:** the eight statutory PPAT act types, unified under the Deed model with
`category = ppat`.

- Fields: matter, `deed_type_id` (AJB / Hibah / Tukar Menukar / Pemasukan ke Perusahaan /
  Pembagian Hak Bersama / APHT / Pemberian HGB-Hak Pakai / Pelepasan Hak), land object
  (`land_object_id`, see 3H), parties (transferor/transferee or equivalent per type, see 3J),
  transaction value, signing date.
- **Hard gate**: a PPAT deed cannot move to `signed` until (a) Land Due Diligence (3I) for the
  linked land object shows no unresolved blocking issues, and (b) both required tax obligations
  (3K) are `paid_and_validated` — mirrors the real host-to-host DJP↔BPN check, enforced here as
  an application-level workflow gate rather than an actual integration (see §5 scope note).
- On `signed`: same protocol-numbering + `LegalDeedSigned` event as 3C, plus auto-creates a
  pending `bpn_submissions` row (3L) since every one of these deed types requires a follow-up
  BPN action.

## 3H. Land Object Registry

**Purpose:** a reusable record per parcel/certificate, so due diligence, deeds, and future
transactions on the same land build a history instead of re-entering data.

- Fields: certificate type (`SHM`/`HGB`/`HGU`/`Hak Pakai`/other), certificate number, NIB
  (Nomor Identifikasi Bidang), address/location, area (m²), NJOP reference, current registered
  owner (`partner_id`, informational — the certificate is the source of truth, not this field),
  status (`active`/`in_transaction`/`transferred`/`disputed`).
- Detail view: linked deeds (any deed referencing this object), due-diligence history, current
  status.

## 3I. Land Due Diligence (Engine)

**Purpose:** the structured pre-transaction checklist every PPAT deed depends on.

- `due_diligence_checks`: `land_object_id`, check type (`sertifikat_validity` /
  `pbb_payment_status` / `blokir_sengketa` / `zona_nilai_tanah`), status
  (`pending`/`clear`/`flagged`), checked_by, checked_at, result notes, evidence (DMS
  attachment — e.g. the SKPT scan).
- A `flagged` result on any check blocks the linked deed(s) from signing until resolved or
  explicitly overridden by the responsible notary/PPAT with a logged justification — the
  override path exists because real practice sometimes proceeds with a documented risk
  acceptance, but it must never be silent.
- Field checks (e.g. a physical site visit) are the natural trigger for a Field Visit (3M).

## 3J. Party / Appearer Management

**Purpose:** every deed's parties, linked to the firm-wide contact registry without losing the
deed's point-in-time accuracy.

- `deed_parties`: `deed_id`, `partner_id` (FK `CRM.partners`), role (`penghadap`/
  `pihak_pertama`/`pihak_kedua`/`saksi`/`kuasa`/`ahli_waris`/other, tenant-editable lookup —
  same pattern as CRM's role types), and an **identity snapshot** (name, ID number/NIK,
  address, and any other identity fields as they existed at signing time).
- **Why snapshot, not live reference**: a signed deed is a legal record of who appeared and
  what their stated identity was *at that moment*. If the underlying `CRM.partners` record is
  later corrected (e.g. an address update), the deed must not silently reflect the new data —
  the snapshot is what's authoritative for that instrument, forever. This is the same
  discipline DMS applies to versioning (3C of `DMS_SPECS.md`: never overwrite, always a new
  version) applied to identity instead of files.
- A party with no existing `CRM.partners` match can be quick-added inline (creates a minimal
  Contact) — mirrors CRM's own "convert without re-entering data" philosophy (3D of
  `CRM_SPECS.md`).

## 3K. Tax Tracking Engine

**Purpose:** track the two mandatory taxes on PPAT land transfers accurately enough to gate
signing, without becoming a tax-filing system.

- `deed_taxes`: `deed_id`, tax type (`pph_final` / `bphtb`), taxpayer role (seller for PPh,
  buyer for BPHTB — auto-defaulted from the deed's party roles, per PP No. 34/2016 and UU No.
  28/2009), base amount (transaction value or NJOP, whichever is higher — both fields captured
  so the higher one is transparent, not just assumed), rate (2.5% for PPh Final; 5% for BPHTB,
  each tenant-configurable in case future regulation changes the rate), NPOPTKP applied
  (tenant-configurable — it's a local-government figure, varies by Kabupaten/Kota Bapenda),
  computed amount, billing code (Kode Billing / Coretax reference for PPh, SSPD reference for
  BPHTB), NTPN (Nomor Transaksi Penerimaan Negara — proof of payment), payment evidence (DMS
  attachment), status (`pending`/`billing_code_issued`/`paid`/`validated`).
- `validated` is a distinct status from `paid` deliberately — real practice has the PPAT (or
  the DJP/Bapenda host-to-host check) confirm payment is *recognized*, not just that a transfer
  was made; only `validated` satisfies the signing gate in 3G.
- This engine **computes and tracks**, it does not remit payment or file returns — Coretax
  (DJP) and each local Bapenda's own SSPD system remain the systems of record, consistent with
  the module boundary already drawn for DMS (search does not replace source documents) and WNE
  (notification does not replace the business transaction).

**Rules / logic**
- `LEGAL.deed_taxes` tracks the **client's own tax obligations on their land transaction** —
  the seller's PPh Final and the buyer's BPHTB — not the firm's own books. This is
  deliberately separate from, and has no relationship to, **Accounting**'s Indonesian Tax
  Engine (`ACCOUNTING_SPECS.md` §3M), which tracks the *firm's own* PPN/PPh withholding as a
  taxpayer/withholding agent on its own AR/AP transactions (e.g. PPh 4(2) on a vendor rent
  bill the firm itself pays). The naming overlap between "PPh Final on a land transfer" (here)
  and "PPh 4(2)" as a general withholding type on the firm's own bills
  (`ACCOUNTING_SPECS.md` §3M) is coincidental, not a shared concept — `LEGAL.deed_taxes` never
  produces an Accounting journal entry or Bukti Potong. A client's BPHTB/PPh Final payment is
  money that moves between the client and the government (evidenced to the notary), not a
  transaction on the firm's own general ledger.

## 3L. BPN Registration Tracking

**Purpose:** the post-signing land registry step (balik nama, APHT/HT-el registration,
split/merge, etc.), tracked as a checklist/status log.

- `bpn_submissions`: `deed_id`, submission type, submitted date, tracking/receipt number, PNBP
  fee amount (formula-assisted: `(nilai_tanah / 1000) + Rp 50.000` per current BPN PNBP
  convention, editable since local variations exist), status
  (`prepared`/`submitted`/`in_process`/`completed`/`rejected`), completed date, resulting
  document (new/updated certificate, attached via DMS).
- Rejection requires a reason and re-submission is a new row referencing the prior one
  (`resubmission_of_id`) — never an edit-in-place, same non-destructive philosophy as
  DMS versioning and the deed immutability rule in 3C.
- Explicit MVP scope note (§5): no live BPN API exists at solo-dev-firm scale, so this is a
  manually-updated tracker, not a system integration — the value is centralizing status
  visibility and deadlines, not automating the government side.

## 3M. Field Operations (Mobile)

**Purpose:** the one workflow that genuinely lives away from a desk.

- **Visit scheduling**: a field visit (`field_visits`) is created against a matter/land object/
  deed, with type (`site_survey`/`bpn_office_visit`/`document_pickup`/`signing_witness`/other),
  assigned field operator, and a linked `Schedule` calendar item (reuses Schedule's Task/Event
  model — Legal does not build its own calendaring, per the Core/Vertical boundary).
- **Mobile check-in flow**: operator opens the visit on their phone, GPS location is captured
  at check-in, photos/scans are captured directly into DMS (tagged to the matter/land
  object/deed), a short checklist (per visit type, tenant-configurable) is completed, and a
  closing note is added. Status flows `scheduled → checked_in → completed`.
- **Offline tolerance**: since BPN offices and rural land sites often have poor connectivity,
  the mobile client queues check-in data and photo uploads locally and syncs when back online —
  this is the concrete reason this workflow gets a real mobile client instead of a responsive
  web page (see §5).
- Completing a visit can directly update the linked `due_diligence_checks` or
  `bpn_submissions` status (e.g. "site checked, no dispute found" or "certificate collected") —
  closes the loop between field work and the office-side record without re-keying.

# 4. Storage

**Database (schema `LEGAL`, tenant DB — consistent with `CLAUDE.md` §7A; no `tenant_id`
column, isolation is the database boundary):**

**Master / lookup tables**
- `LEGAL.deed_types` — code, name, category (`notary`/`ppat`), requires_tax (bool),
  requires_bpn_registration (bool), default protocol book type.
- `LEGAL.party_role_types` — tenant-editable lookup (penghadap, pihak_pertama, saksi, kuasa,
  ahli_waris, ...), mirrors `CRM.partner_role_types` pattern.
- `LEGAL.field_visit_types` — lookup with a configurable default checklist (JSON).

**Transaction / core tables**
- `LEGAL.matters` — header: title, matter_type, primary `partner_id` (FK `CRM.partners`),
  assigned_to, status, opened_at, target_close_at, `converted_from_lead_id` (nullable, FK
  `CRM.leads`).
- `LEGAL.deeds` — header: `matter_id` (nullable), `deed_type_id`, category, deed_number
  (assigned on signing), status, signing_date, minuta_reference, summary.
- `LEGAL.deed_parties` — `deed_id`, `partner_id` (FK `CRM.partners`), `role_type_id`, identity
  snapshot (JSON: name, ID number, address, etc. as of signing).
- `LEGAL.wills` — `deed_id` (FK, category=notary/wasiat), testator `partner_id`, dpw_reg_number,
  dpw_registered_at, status.
- `LEGAL.land_objects` — certificate_type, certificate_number, nib, address, area_m2,
  njop_reference, current_owner `partner_id` (informational), status.
- `LEGAL.due_diligence_checks` — `land_object_id`, check_type, status, checked_by, checked_at,
  result_notes.
- `LEGAL.deed_taxes` — `deed_id`, tax_type, taxpayer `partner_id`, base_amount, njop_amount,
  rate, npoptkp_applied, computed_amount, billing_code, ntpn, status.
- `LEGAL.bpn_submissions` — `deed_id`, submission_type, submitted_at, tracking_number,
  pnbp_amount, status, completed_at, `resubmission_of_id` (nullable, self-referencing).
- `LEGAL.protocol_books` — book_type, year, volume, notary (internal user), status, opened_at,
  closed_at, handed_over_to, handed_over_at.
- `LEGAL.protocol_entries` — `book_id`, `deed_id` (nullable, polymorphic-ish reference to
  deed/will/legalization/waarmerking), sequence_number, entry_date. Append-only.
- `LEGAL.field_visits` — `matter_id`, `land_object_id`/`deed_id` (nullable), `visit_type_id`,
  assigned_to, scheduled `schedule_item_id` (FK `SCHEDULE.sched_items`), status, checked_in_at,
  gps_lat, gps_lng, checklist_result (JSON), notes.

**Custom fields:** `deeds`, `matters`, `land_objects` are all registered against the existing
`CUSTOMFIELDS` schema (per `CLAUDE.md` §7A) — deed-type-specific fields (e.g. share capital for
an Akta Pendirian PT, exchange terms for Tukar Menukar) are tenant/type-configurable custom
fields, not one migration per deed type.

**Object file storage** (per `CLAUDE.md` §7B — reserves a `LEGAL/` folder per tenant; actual
files live in DMS's storage structure, referenced by `subject_type = 'legal.deeds'` etc.),
following DMS's own canonical path convention exactly (`DMS_SPECS.md` §4:
`{owning_module}/{yyyy}/{mm}/{document_uuid}/v{n}.{ext}`), not a Legal-specific layout:
```text
tenant_001/DMS/LEGAL/{yyyy}/{mm}/{document_uuid}/
├── v1.{ext}
└── ...
```
A matter, deed, or field visit's documents are found by querying DMS for
`subject_type = 'legal.matters'` / `'legal.deeds'` / `'legal.field_visits'` and the relevant
`subject_id` (per §3B/§3M above) — grouping is a database query against DMS's
`subject_type`/`subject_id` columns, not a folder-path convention. The physical path exists
for storage/restore-planning purposes only (per `CLAUDE.md` §7B), so it stays identical to
every other module's DMS-routed documents rather than encoding Legal-specific structure into
it.
- Legal itself stores no files directly — every document (deed exports, scanned certificates,
  KTP scans, tax payment proofs, field photos) goes through `DocumentService` (DMS facade),
  same as every other module. This keeps versioning, audit trail, and retention consistent
  platform-wide instead of Legal reinventing file handling.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Vertical module at `app/Modules/Legal/`, same shape as every Core
module (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`, `Routes/`).
Legal depends on CRM, DMS, and Schedule (Vertical → Core, the only allowed direction per
`CLAUDE.md` §2/§9) and integrates with WNE the same decoupled way every other module does —
Legal never implements notification/approval logic itself.

**Category placement (per `CLAUDE.md` §10 — state category before building):**
- `LEGAL.*` tables, deed/matter/protocol/tax/land logic → **Vertical**. This is Legal-specific
  domain knowledge (deed types, protocol books, Indonesian tax mechanics) that has no reuse
  case in Property or a future vertical.
- Party linkage, document storage, scheduling, notifications → consumed **from Core**
  (`CRM.partners`, `DocumentService`, `SCHEDULE.sched_items`, WNE events) via facade/event, per
  the same seam pattern already established in `DMS_SPECS.md` §5 and `SCHEDULE_SPECS.md` §5.
- **No new microservice** — Legal is CRUD plus a handful of workflow gates (tax clearance, due
  diligence) and a numbering/ledger engine, none of which need a different runtime or
  independent scaling per `CLAUDE.md` §2's extraction criteria.

**Cross-schema FK direction:** `LEGAL.matters.partner_id`, `LEGAL.deed_parties.partner_id`, and
`LEGAL.land_objects.current_owner` all FK directly into `CRM.partners.id` — safe because it's
Vertical depending on Core (same reasoning `CRM_SPECS.md` §5 already documents for its own
cross-schema FKs). CRM has, and will always have, zero knowledge of `LEGAL.*`.

**Deed immutability:** once a deed reaches `signed`, the deed record, its `deed_parties`
identity snapshots, and its assigned protocol sequence number become read-only at the
application layer. Corrections happen via a new amending deed referencing the original
(`amends_deed_id`), never an edit — this mirrors the non-destructive philosophy already used
for DMS versions (§3C, `DMS_SPECS.md`) and CRM merges (§3G, `CRM_SPECS.md`), applied to the one
domain where it's a *legal*, not just a data-hygiene, requirement.

**Tax gate is a workflow safeguard, not a filing system — explicit scope boundary:** `deed_taxes`
tracks status up to `validated`; Legal does not call Coretax or any Bapenda system. This keeps
the module honest about what it owns: the PPAT/notary still performs the actual filing/payment
through DJP Coretax and the local Bapenda SSPD process, and marks status in Legal. If a future
version of this platform wants a real integration, `deed_taxes.billing_code`/`ntpn` are already
the join keys needed — additive, not a redesign.

**BPN registration is a tracker, not an integration — same reasoning.** No public API exists at
this scale; `bpn_submissions` exists to centralize visibility and deadlines across a firm's
matters, which is itself the sellable value (a solo practitioner or small firm currently tracks
this in spreadsheets or memory).

**Mobile Field Operations — the one justified API exception:** `CLAUDE.md` §2's "Web vs future
clients" section explicitly allows a versioned REST API once "a non-Inertia client is real, not
speculative." Field visits are that case: a phone at a BPN office or a rural land site, with
real offline-tolerance needs (GPS check-in, photo capture queued for sync), is a genuinely
different client shape than a desktop Inertia page — not a stylistic preference for a native
feel. Scope the exception narrowly: a thin, versioned `api/v1/legal/field-visits/*` surface
that calls the same `FieldVisitService` the web app uses internally, so there is exactly one
place business logic lives, per `CLAUDE.md` §2's "no duplicated domain logic" rule. Everything
else in Legal (matters, deeds, tax, BPN tracking, protocol books) stays desk-bound Inertia —
resist the temptation to extend the mobile surface further than field visits actually need.

**Protocol ledger integrity:** `protocol_entries.sequence_number` must be gap-free within a
`(book_id, year)` pair — assign it inside the same DB transaction that flips a deed to `signed`
(or completes a legalization/waarmerking), using a row lock on the active `protocol_books` row
to prevent race conditions from two deeds signing concurrently. No update/delete permitted on
`protocol_entries` at the app layer, same audit-integrity rule as `DMS.access_logs`.

**Regulatory reference basis (for Claude Code's context, not stored as data):** UU No. 30/2004
jo. UU No. 2/2014 (Jabatan Notaris — protocol, wills, legalization/waarmerking obligations);
PP No. 24/1997 and PPAT regulations under Kementerian ATR/BPN (the eight statutory PPAT deed
types); PP No. 34/2016 (PPh Final atas pengalihan hak atas tanah dan/atau bangunan, 2.5%);
UU No. 28/2009 (BPHTB, a local/daerah tax, 5% net of NPOPTKP, NPOPTKP set per Kabupaten/Kota).
Rates and thresholds are stored as tenant-configurable values (§3K), not hardcoded, since local
NPOPTKP figures vary and national rates are set by regulation that can change — the engine
should never require a code deploy to reflect a rate change.

**Suggested build order for Claude Code:** 3B/3C (matters + notarial deeds, the simplest
end-to-end slice) → 3J (party linkage, needed by everything else) → 3F (protocol numbering,
since even 3C needs it on signing) → 3H/3I (land object + due diligence) → 3G (PPAT deeds,
depends on 3H/3I/3K) → 3K (tax engine) → 3L (BPN tracking) → 3D/3E (wills, legalization/
waarmerking — same patterns as 3C, lower complexity) → 3M (field operations, including the
mobile API surface) — ship at this point — then revisit Future Version items (e-meterai, AHU/
BPN integrations, billing) once there's real usage to justify the build.

**Marketability notes**
- The protocol/ledger and tax-gate features are the hardest to build well and the easiest to
  sell — they're the difference between "a document folder with extra steps" and "software that
  understands what a Notaris/PPAT is legally on the hook for." Lead demos with these, not the
  CRUD screens.
- Standalone-first (no forced CRM/DMS/Schedule purchase) matches how small Notaris/PPAT
  practices actually buy software — lets Legal be sold to a solo practitioner cheaply, then
  upsell Core modules as the practice grows, same monetization shape already established for
  DMS and Schedule.
- Field Operations mobile support is a concrete, demoable differentiator against desktop-only
  competitors, directly reflecting the "must be reused... independently" and "genuinely
  sellable" bias already applied to Schedule's ICS feed feature.
