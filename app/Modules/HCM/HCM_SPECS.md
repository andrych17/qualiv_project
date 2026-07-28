# HCM Module
## Human Resources / Human Capital Management — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every tenant on the platform — regardless of which vertical they rent (Legal today, Property
later) — has employees of their own to manage: a law firm has partners, associates, paralegals,
and admin staff; a property manager has agents and maintenance staff. This is **internal
workforce data**, distinct from `CRM.partners` (external clients/vendors/leads) even though the
two modules superficially look similar (people, roles, contact info) — see §5 for why they are
deliberately **not** the same table.

Left unsolved centrally, this repeats the exact anti-pattern WNE, DMS, CRM, and Schedule were
each built to avoid:

- Every tenant currently manages employee records, leave, and payroll in spreadsheets or a
  disconnected third-party tool — no single source of truth, no audit trail, no self-service.
- **Indonesian statutory payroll is complex and changes yearly** (PPh 21 TER rates, BPJS
  contribution caps, regional minimum wage/UMP-UMK, THR timing) — getting this wrong is a
  compliance and reputational risk for any tenant, and building it generically (not
  Indonesia-specific) would make the product unsellable in this market.
- No shared approval path for leave requests, payroll runs, or contract changes — every tenant
  would otherwise want its own bespoke sign-off chain.
- No shared calendar for who's on leave, on shift, or unavailable — Schedule already solves
  "what's happening when," HCM should feed it, not duplicate it.
- No central place to store employment contracts, ID scans, and certifications with proper
  retention — DMS already solves this; HCM should feed it, not duplicate it.

**Client requirements:**
- Multi-tenant aware, DB-per-tenant isolation like every other Core module (no `tenant_id`
  column — see `CLAUDE.md` §4/§7).
- Must work **standalone** — sellable as its own line item to a tenant who hasn't bought
  Schedule, DMS, or a vertical module — but integrates cleanly with WNE, DMS, and Schedule when
  present, same decoupled seam pattern as every other Core module.
- **Must comply with Indonesian labor law and tax regulation** from day one for the modules
  that touch pay and employment status: PKWT/PKWTT contract types, PPh 21 (TER method),
  BPJS Kesehatan, BPJS Ketenagakerjaan (JKK/JKM/JHT/JP), THR, and statutory overtime — because
  Payroll is the module where "close enough" is not sellable; it's a legal-risk item for the
  tenant.
- Statutory rates (tax tables, BPJS percentages/caps, regional minimum wage) must be
  **configurable, versioned master data**, not hardcoded — Indonesian government regulations
  (PMK, Permenaker) change these figures on a roughly annual cycle.
- **Quick implementation is the priority.** Ship a lean, correct core (employee data, leave,
  attendance, compliant payroll, self-service) fast; defer everything strategic-but-not-blocking
  (ATS, Performance, LMS, Talent, structured Compensation planning, Benefits enrollment, deep
  Analytics) to Future Version, per the submodule table the client provided.

# 2. Goals

> Designated features. MVP-first, matching the client's own submodule table — every submodule
> gets at least a data home so nothing needs a breaking migration later, but only some ship
> full functionality at launch.

**MVP (ship first — the four submodules with genuine legal/day-to-day urgency, plus ESS as the
front door to all of them)**

| Submodule | MVP scope |
|---|---|
| **HR / Core HCM** | Full — this is the foundation every other submodule (and every future one) depends on. |
| **Time & Attendance** | Simple clock in/out (web + mobile-responsive), shift assignment, basic late/absence flag. |
| **Leave Management** | Policy setup, entitlement/balance, request → WNE approval → balance deduction. Indonesian statutory leave types pre-seeded (annual, sick, maternity, marriage, bereavement, Hajj). |
| **Payroll** | Indonesia-compliant: PPh 21 (TER method), BPJS Kesehatan + Ketenagakerjaan, THR calculation, statutory overtime, payslip generation, bank-transfer-ready payment file. Multi-run/retroactive correction workflows deferred. |
| **Employee Self-Service** | Employee + manager portal: profile, payslip download, leave request/approval, attendance view, document access (via DMS). |

**Future Version (post-launch — data model stubbed now so no breaking migration later)**

| Submodule | Deferred scope / reason |
|---|---|
| **Recruitment / ATS** | Full candidate pipeline. MVP ships only a minimal "hire → creates Employee" entry point (see §3D) since Payroll/Core HR need *a* way to onboard someone; the sourcing/interview pipeline itself is a separate sellable add-on, lower urgency than getting existing employees paid correctly. |
| **Performance** | Goals/KPI/appraisal cycles — valuable but not blocking; needs Core HR + org structure to exist first anyway. |
| **Learning / LMS** | Training/skills/certifications — genuinely a different product shape (content delivery); best split out once there's real demand. |
| **Talent Management** | Career/succession planning — depends on Performance data existing first; premature before that. |
| **Compensation** | Structured salary bands/grades and comp planning cycles — MVP Payroll uses a simple per-employee base salary field; formal banding is a v2 upsell. |
| **Benefits** | Enrollment beyond the statutory BPJS already in MVP Payroll (e.g. private insurance, allowances catalog) — real feature, not urgent. |
| **HR Analytics** | Deep workforce reporting/dashboards beyond the MVP Main Dashboard's headline numbers (see 3A) — needs a few payroll cycles of real data to be worth building. |

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, database
> design.

## 3A. Main HCM Dashboard

**Function / features**
- Headline cards: Active Employees, On Leave Today, Pending Leave Approvals, Payroll Run Status
  (this period).
- "My work" queue (mirrors WNE's "My Approvals" / CRM's "My work" pattern): leave requests
  awaiting my approval, contracts expiring in the next 30/60/90 days, probation periods ending
  soon.
- Org chart snapshot (collapsed by default, expandable) driven by `hcm.positions` reporting
  lines.

**Layout**
- Top: 4 summary cards, per `DESIGN.md`.
- Main: tabbed table — "Pending Approvals" | "Expiring Contracts" | "Today's Attendance
  Exceptions" | "Recent Hires" — shared Data Table component, Status Rail colored by urgency.
- Row click opens the relevant record's drawer/detail page.

**Rules / logic**
- Every list scoped to the current tenant DB automatically (DB-per-tenant, no app-level filter
  needed — same as Schedule and DMS).
- Visibility respects role: an employee sees only their own data; a manager sees their direct
  reports (resolved via `hcm.positions.reports_to_position_id`); HR admin sees all.

## 3B. Employee Master (Core HR)

**Purpose:** the single source of truth for "who works here" — everything else in HCM, and
eventually Payroll/Time/Leave/ESS, hangs off this record.

- **Identity & statutory fields:** full name, date of birth, gender, `nik` (KTP/national ID
  number, 16 digits), `npwp` (tax ID, optional — affects PPh 21 rate category if absent, see
  3G), `bpjs_kesehatan_no`, `bpjs_ketenagakerjaan_no`, address, marital status, number of
  dependents (drives `ptkp_status` — see 3G), religion (used only to compute the correct
  religious-holiday date for THR timing, never surfaced elsewhere as a filter/report field).
- **Employment fields:** employee number (tenant-configurable format), hire date, employment
  status (`active` / `on_leave` / `suspended` / `terminated`), position (FK →
  `hcm.positions`), department/org unit, direct manager (derived from position's reporting
  line), bank account (for payroll disbursement, 3G).
- **Contract summary tab:** current + historical `hcm.employment_contracts` (see 3D).
- **Documents tab:** attached via `DocumentService::attach()` into DMS
  (`subject_type = 'hcm.employees'`) — contract PDFs, ID scans, certificates. HCM stores no
  files itself, same pattern DMS already establishes for every other module.
- **Leave balance tab, Attendance summary tab, Payslip history tab** — read-through to 3F/3E/3G.
- List view: shared Data Table, filters by department/position/status, Status Rail colored by
  employment status (`active` = success/neutral, `on_leave` = info, `suspended` = warning,
  `terminated` = neutral border/archived).

**Rules / logic**
- Terminating an employee never hard-deletes the record — sets `employment_status = terminated`
  + `termination_date` + `termination_reason`, preserving history for statutory record-keeping
  (Indonesian labor law expects employers to retain employment records) and for payroll's
  severance (`pesangon`) calculation.
- Changing position/department is logged (`hcm.employee_position_history`) — needed for
  tenure-based statutory calculations (leave entitlement, severance, THR proration) that depend
  on "when did this person hold what role."

## 3C. Organization & Job Structure (Core HR)

**Purpose:** the org chart and job catalog that Positions, Payroll, and reporting-line
resolution all depend on.

- `hcm.org_units` — tree (department/division/branch), self-referencing `parent_org_unit_id`.
- `hcm.jobs` — job title/catalog (master), independent of any specific person filling it.
- `hcm.positions` — a specific seat: `job_id`, `org_unit_id`, `reports_to_position_id`
  (self-referencing — this is what drives manager resolution across the whole module, same
  self-referencing pattern CRM uses for `parent_partner_id`), headcount cap (optional,
  informational in MVP — no enforcement/budget engine yet).
- Simple tree-view CRUD screens for both Org Units and Positions, consistent with DMS's Folder
  Management (3D) UX pattern.

**Rules / logic**
- A Position can be vacant (no current employee) — Payroll/Attendance never reference
  Positions directly for pay, only through the Employee currently occupying one, so a vacancy
  never breaks anything downstream.

## 3D. Employment & Contracts (Core HR)

**Purpose:** track the legal basis of employment — required for Indonesian compliance, since
contract type governs probation rules, notice periods, and severance eligibility.

- `hcm.employment_contracts`: `employee_id`, contract type (`PKWT` fixed-term / `PKWTT`
  permanent), start date, end date (required for PKWT, null for PKWTT), base salary, work
  location, `probation_end_date` (PKWTT only — Indonesian law disallows probation on PKWT),
  status (`active` / `expired` / `terminated` / `renewed`), document reference (DMS).
- PKWT renewal/extension is tracked as a new row linked via `renewed_from_contract_id` — needed
  because Indonesian regulation (PP 35/2021) caps total PKWT duration including extensions at
  5 years; the system should be able to answer "how long has this person been on PKWT in total"
  without recomputation being ambiguous.
- **Minimal hire entry point (stands in for full ATS until Future Version):** a single "New
  Hire" action creates the Employee (3B) + first Contract (3D) + initial Position assignment
  (3C) in one form — the Recruitment/ATS submodule later replaces the *sourcing* step in front
  of this, not this step itself.

**Rules / logic**
- Contract expiring within a configurable window (default 60 days) surfaces on the Dashboard
  (3A) and fires an `hcm.contract_expiring` event → WNE, so renewal/non-renewal decisions
  aren't missed — reuse WNE, no parallel reminder logic in HCM.
- Probation end approaching similarly surfaces via WNE.

## 3E. Time & Attendance

**Purpose:** know who worked when, for both operational visibility and as an input to Payroll's
overtime calculation.

- **Shifts:** `hcm.shifts` (name, start/end time, break duration) assignable per employee per
  day via `hcm.shift_assignments`. Simple fixed-shift model for MVP — complex rotating-roster
  auto-generation is Future Version.
- **Clock in/out:** web button (mobile-responsive, per `DESIGN.md`) records `hcm.attendance_logs`
  (`employee_id`, `clock_in_at`, `clock_out_at`, source = `web` for MVP). Geo/photo-verified
  clock-in and biometric device integration are Future Version — not needed to be sellable at
  launch for an office-based legal-vertical tenant.
- **Exceptions:** late arrival / early leave / no-show computed against the assigned shift,
  surfaced with a Status Rail (`danger` = absent unexplained, `warning` = late, `success` =
  on-time) on a daily attendance list.
- **Correction requests:** employee can submit a correction (e.g. forgot to clock out) which
  routes through WNE for manager approval — reuse WNE, same as Leave (3F).

**Rules / logic**
- Attendance data feeds Payroll's overtime engine (3G) directly — hours beyond the shift's end
  time, subject to the statutory overtime formula, not a flat rate.
- If Schedule module is enabled for the tenant, shift assignments are optionally mirrored as
  read-only calendar entries in Schedule for unified calendar visibility — HCM publishes
  `hcm.shift_assigned`; Schedule has zero compile-time dependency on HCM, same as every other
  cross-module integration in this codebase.

## 3F. Leave Management

**Purpose:** policy-driven leave entitlement, request, and approval — pre-seeded with
Indonesian statutory leave types so a tenant is compliant out of the box.

- `hcm.leave_types` (tenant-editable, pre-seeded): Annual (`cuti tahunan`, statutory minimum 12
  days/year after 12 months' service), Sick (`cuti sakit`, doctor's note required past a
  configurable threshold), Maternity (`cuti melahirkan`, 3 months per Indonesian law), Paternity
  (2 days), Marriage (3 days), Bereavement (2 days, immediate family), Hajj/religious pilgrimage
  (up to 3 months, unpaid, once per employment per statute), plus tenant-defined custom types.
- `hcm.leave_policies`: per tenant × leave type × employment status, entitlement days/year,
  accrual method (annual grant vs monthly accrual), carry-over rule (max days, expiry), paid
  vs unpaid.
- `hcm.leave_balances`: per employee × leave type × period, running balance.
- `hcm.leave_requests`: employee, type, date range, reason, status
  (`pending`/`approved`/`rejected`/`cancelled`), attachment (doctor's note etc., via DMS).

**Rules / logic**
- Submitting a request fires `WorkflowRequested` into WNE (`workflow_code =
  hcm.leave_approval`) — HCM does not implement approval-chain logic itself, same pattern CRM's
  lead qualification uses. Approval/rejection callback updates `leave_requests.status` and, on
  approval, decrements `leave_balances`.
- Balance check runs at submission time (soft warning, tenant-configurable whether negative
  balance blocks submission or just flags it — some tenants allow advance/negative leave).
- Approved leave overlapping a shift automatically excuses that day from Attendance exceptions
  (3E) — no double-flagging a legitimately absent employee as "no-show."

## 3G. Payroll Engine — Indonesia-Compliant

**Purpose:** the highest-stakes submodule — must be correct, not just fast. Runs monthly payroll
producing a compliant payslip and a bank-transfer-ready payment file.

**Statutory rate tables (versioned master data — the core design decision for this engine):**
- `hcm.ptkp_statuses`: PTKP (non-taxable income) categories (TK/0, TK/1, TK/2, TK/3, K/0, K/1,
  K/2, K/3, ...) mapped to annual non-taxable amount and **TER category** (A/B/C, per PMK
  168/2023's simplified monthly withholding method).
- `hcm.ter_rates`: TER category × income bracket → monthly effective withholding rate. Loaded
  as versioned, effective-dated data (`effective_from`/`effective_to`) — when the government
  revises rates, a tenant admin loads a new table version; no code deploy required.
- `hcm.bpjs_rates`: contribution type (Kesehatan / JKK / JKM / JHT / JP), employer %, employee
  %, wage floor/ceiling for the contribution base — also versioned/effective-dated (JKK rate
  additionally varies by tenant's registered risk class, a field on the tenant's HCM settings).
- `hcm.regional_minimum_wages`: province/city (UMP/UMK) × year — informational compliance
  check at contract creation (3D), warns if `base_salary` is below the applicable minimum, does
  not hard-block (tenant may have valid exemptions).

**Payroll run:**
- `hcm.payroll_periods`: month/year, status (`draft` → `calculated` → `approved` → `paid` →
  `closed`).
- `hcm.payroll_run_items`: per employee per period — base salary, attendance-derived overtime
  (per Kepmenaker formula: 1.5× hourly rate for the 1st overtime hour, 2× for subsequent hours,
  computed from `hcm.attendance_logs`), allowances, BPJS Kesehatan + Ketenagakerjaan
  (JKK/JKM/JHT/JP) employer and employee portions (computed from `bpjs_rates` against gross,
  respecting the wage ceiling), PPh 21 withholding (computed via TER against `ptkp_status`, with
  year-end reconciliation using the progressive statutory rates — 5/15/25/30/35% brackets —
  deferred to a December/final-period run rather than every month, per how TER is designed to
  work), other deductions, net pay.
- **THR (`Tunjangan Hari Raya`):** a separate, non-monthly run — one month's base salary,
  pro-rated for employees with under 12 months' tenure (`tenure_months / 12 × base_salary`),
  generated ahead of the employee's registered religious holiday (per `hcm.employees.religion`)
  with the statutory H-7 (7 days before) payment deadline surfaced as a Dashboard reminder via
  WNE.
- **Payslip:** generated per employee per period (PDF via the existing document-generation
  approach used elsewhere in the platform), stored in DMS (`subject_type =
  'hcm.payroll_run_items'`), accessible to the employee via ESS (3H).
- **Payment file:** simple bank-transfer-ready export (CSV, generic format — tenant's bank
  specific format templates are a Future Version add-on, not a blocker) once a run is
  `approved`.

**Rules / logic**
- Payroll run approval routes through WNE (`workflow_code = hcm.payroll_approval`) before
  moving from `calculated` to `approved` — reuse WNE, no parallel approval logic.
- Once a period is `closed`, its `payroll_run_items` are immutable; corrections happen via a
  new adjustment row in the *next* period, never by editing history — same audit-integrity
  principle DMS applies to its access log (3I) and CRM applies to merge logs (3G).
- Severance (`pesangon`)/termination pay calculation (UU Cipta Kerja formula, tenure-banded) is
  a manual-trigger one-off calculation off the Employee record at termination time, using the
  same rate tables — not a recurring payroll run.

## 3H. Employee Self-Service (ESS) Portal

**Purpose:** the front door for every employee/manager — reduces HR admin load, which is the
single biggest "why should I pay for this" argument for a solo-dev-built HCM product.

- **Employee view:** my profile (view/edit non-statutory fields, statutory fields request-only
  via HR), my payslips (list + download, from 3G), my leave balance + request form (3F), my
  attendance log + clock in/out (3E), my documents (DMS-backed).
- **Manager view:** everything above for self, plus team attendance today, pending approvals
  (leave/attendance corrections) inbox, team roster.
- Built on the same shared component library (`DESIGN.md`) as every other module's UI — no
  separate design language for ESS.

**Rules / logic**
- ESS is a permission lens over the same underlying tables (3B–3G), not a separate data store —
  "my data" vs "my team's data" vs "all data" is a scoping rule in the Service layer, per
  `CLAUDE.md` §6's "business logic in Services" convention.

## 3I. Recruitment / ATS — **Future Version**

- Candidate pipeline (source → screen → interview → offer → hire), job requisition/posting,
  interview scheduling (would reuse Schedule for interview slots, same pattern as everything
  else), offer letter generation (via DMS templating).
- MVP ships only the "New Hire" entry point in 3D; the sourcing pipeline in front of it is
  built here once there's demand to justify it.

## 3J. Performance Management — **Future Version**

- Goal-setting (individual/team), KPI tracking, review cycles (self/manager/360), rating scales,
  calibration. Depends on Org Structure (3C) existing, which it does — this is a "when," not a
  "how," problem, deferred purely for build-time priority.

## 3K. Learning / LMS — **Future Version**

- Course catalog, assignment, completion tracking, certification expiry tracking (would reuse
  WNE for expiry reminders, same pattern as DMS retention). Genuinely different product shape
  (content authoring/delivery) — likely candidate for its own module boundary or even
  extraction if it grows large, revisit per `CLAUDE.md` §2 extraction criteria when it's built.

## 3L. Talent Management — **Future Version**

- Career pathing, 9-box succession grid, talent pool tagging. Depends on Performance (3J) data
  existing first — sequencing reason, not a design gap.

## 3M. Compensation — **Future Version**

- Salary bands/grades per job (3C), structured comp review cycles, merit increase workflows
  (would route through WNE). MVP Payroll's flat `base_salary` field on the contract (3D) is
  forward-compatible — banding is additive metadata on top, not a schema rework.

## 3N. Benefits — **Future Version**

- Enrollment for non-statutory benefits (private health insurance top-up, meal/transport
  allowance catalog, life insurance) beyond the statutory BPJS already handled in MVP Payroll
  (3G). Real feature, just not urgent relative to getting core pay compliant first.

## 3O. HR Analytics

- **MVP:** headline numbers on the Main Dashboard (3A) only — headcount, turnover this
  period, pending approvals, payroll run status.
- **Future Version:** dedicated reporting engine — turnover trends, cost-per-hire (once ATS
  exists), attendance/absence trend analysis, payroll cost breakdown by department, exportable
  reports. Natural fit for the same "ask your data" AI analytics pattern used elsewhere on the
  platform (tenant-scoped, read-only DB connection, schema-annotated) once there's a few payroll
  cycles of real data to make it worth building.

---

# 4. Storage

**Database (schema `HCM`, tenant DB — consistent with `CLAUDE.md` §7A, no `tenant_id` column,
DB-per-tenant is the isolation boundary, matching DMS/CRM/Schedule rather than WNE's
`tenant_id`-column outlier):**

**Master / lookup tables**
- `HCM.org_units`, `HCM.jobs`, `HCM.positions`
- `HCM.leave_types`, `HCM.leave_policies`
- `HCM.shifts`
- `HCM.ptkp_statuses`, `HCM.ter_rates`, `HCM.bpjs_rates`, `HCM.regional_minimum_wages`
  (versioned statutory rate tables)

**Employee & employment tables**
- `HCM.employees` (identity, statutory IDs, employment status, position ref, bank account)
- `HCM.employee_position_history`
- `HCM.employment_contracts`

**Time, leave, payroll transaction tables**
- `HCM.shift_assignments`, `HCM.attendance_logs`, `HCM.attendance_corrections`
- `HCM.leave_balances`, `HCM.leave_requests`
- `HCM.payroll_periods`, `HCM.payroll_run_items`, `HCM.thr_run_items`

**Future-Version stub tables (empty/minimal at launch, additive migrations only):**
- `HCM.candidates`, `HCM.job_requisitions` (ATS)
- `HCM.performance_cycles`, `HCM.goals`, `HCM.reviews` (Performance)
- `HCM.courses`, `HCM.enrollments`, `HCM.certifications` (LMS)
- `HCM.salary_bands` (Compensation)
- `HCM.benefit_plans`, `HCM.benefit_enrollments` (Benefits)

**Object file storage:** none owned by HCM directly — all documents (contracts, ID scans,
certificates, payslips, doctor's notes) flow through `DocumentService` into DMS's existing
`tenant_{id}/DMS/HCM/...` path, per `CLAUDE.md` §7B and the DMS facade pattern — no parallel
storage code in HCM, same rule DMS itself applies to every other module.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, same monolithic-modular posture as WNE/DMS/CRM/Schedule,
at `app/Modules/HCM/`. Exposes:
- **Internal facade/service** — `EmployeeService::hire(...)`, `LeaveService::request(...)`,
  `AttendanceService::clockIn(...)`, `PayrollService::runPeriod(...)`,
  `PayrollService::calculatePph21(...)` — preferred integration point for other modules and for
  ESS itself (ESS is a UI/permission layer, not a separate service layer).
- **Internal event bus** — publishes `hcm.employee_hired`, `hcm.contract_expiring`,
  `hcm.leave_requested`, `hcm.leave_approved`, `hcm.payroll_run_completed`,
  `hcm.shift_assigned`. Vertical modules may subscribe (e.g. Legal wanting to know when its
  assigned paralegal is on leave); HCM never subscribes to or calls into vertical modules —
  same one-way Core→never→Vertical rule as everywhere else.

**Why HCM does not reuse `CRM.partners` for employees, despite the surface similarity:**
Employees carry statutory fields (NIK, NPWP, BPJS numbers, PTKP status, employment contract
type) that have no meaning for a CRM partner, and are governed by different lifecycle rules
(termination ≠ merge/deactivate, tenure math feeds legally-mandated calculations CRM has no
concept of). Collapsing them would be the same modeling mistake CRM's own spec calls out for
conflating `type` and `role` — a structural difference disguised as overlap. If a tenant later
wants "is this partner also an employee" cross-linking (e.g. a Legal partner who is also a
referral source), that's a loose `hcm.employees.linked_partner_id` (nullable, informational, not
a FK-enforced merge) — not a shared table.

**Cross-module reuse (decoupled, event-driven — same seam as every other Core module):**
- **WNE** — all approvals (leave, attendance correction, payroll run) and all reminders
  (contract expiring, probation ending, THR due) route through WNE. HCM implements zero
  parallel approval or notification logic.
- **DMS** — all documents (contracts, ID/certificate scans, payslips, leave attachments) flow
  through `DocumentService`. HCM implements zero parallel storage/versioning logic.
- **Schedule** — shift assignments and approved leave can optionally mirror into Schedule as
  read-only calendar entries for unified calendar visibility, if the tenant has Schedule
  enabled. HCM has zero compile-time dependency on Schedule classes — same feature-flag-safe
  pattern Schedule itself already uses for its optional WNE dependency.

**Indonesian compliance — the core design principle:** every statutory figure (PTKP thresholds,
TER rates, BPJS percentages/caps, regional minimum wage) lives in an **effective-dated,
tenant-editable rate table**, never a hardcoded constant or a formula baked into application
code. Regulations (PMK, Permenaker, annual UMP/UMK decrees) change on a roughly yearly cycle;
the correct engineering response is "load a new rate table row," not "ship a code deploy" —
this is the single most important technical decision in this module and should not be
compromised for MVP speed.

**Payroll correctness over speed:** unlike every other MVP scope decision in this platform
(where "ship the simpler sellable version" is the default), Payroll's TER/BPJS/THR calculations
should be built and tested carefully even at MVP, since incorrect payroll is a legal and
reputational risk for the tenant, not a missing nice-to-have feature. Simplicity should come
from *scope* cuts (single payroll run per month, no retroactive multi-period correction engine,
generic CSV bank export instead of per-bank format templates) rather than from cutting corners
on the statutory calculation itself.

**Queues:** Payroll run calculation for a full employee roster runs as a queued batch job
(same `notifications`-adjacent async pattern already established for cross-module events), so
calculating a large tenant's payroll never blocks the UI. Attendance clock-in/out and leave
balance checks are synchronous — fast, user-facing operations, same reasoning Schedule applies
to its own Availability Check (3E in `SCHEDULE_SPECS.md`).

**Extensibility:** new leave types, org unit types, and statutory rate table versions are all
tenant-editable/admin-loadable data — no code deploy needed, same lever CRM's tenant-editable
Role/Lead-source/Ticket-category lookups already establish for cross-vertical reusability.

**Marketability notes**
- Indonesia-compliant Payroll (TER-method PPh 21 + BPJS + THR) is a genuine differentiator
  versus generic international HR software — worth leading with in sales demos for the
  Indonesian SMB/legal-firm market, not burying as a checkbox feature.
- ESS (self-service payslips, leave requests, attendance) is the feature employees *see* daily
  even if HR/finance bought the product — strong day-to-day engagement driver, which matters
  for subscription retention.
- Keeping ATS/Performance/LMS/Talent/Compensation/Benefits as clearly-scoped Future Version
  add-ons (rather than a rushed all-at-once build) mirrors the same "license modules
  separately" upsell strategy already used for CRM's Helpdesk vs. After Sales Service split —
  each can become its own pricing tier later without a schema rework, since stub tables exist
  now.

**Suggested build order for Claude Code:** 3B/3C (Employee + Org core) → 3D (Contracts, incl.
minimal hire entry point) → 3F (Leave, wired into WNE) → 3E (Attendance) → 3G (Payroll —
statutory rate tables first, then the run engine; this is the largest single build item and
should not be rushed) → 3H (ESS, mostly a permission-scoped UI over the above) → 3A (Dashboard)
→ then revisit 3I/3J/3K/3L/3M/3N/3O as Future Version once MVP is validated with a real tenant.
