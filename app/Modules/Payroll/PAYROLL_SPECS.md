# Payroll Module
## Payroll & Indonesian Statutory Compliance Engine — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every tenant that runs people eventually needs payroll, and in Indonesia payroll is not just
arithmetic — it's a compliance surface: PPh 21 income tax (now the TER — Tarif Efektif
Rata-rata — method since PP 58/2023 and PMK 168/2023), two BPJS programs (Kesehatan and
Ketenagakerjaan, each with its own contribution split and wage caps), mandatory THR
(religious holiday allowance, PP 36/2021), and severance/termination pay governed by UU Cipta
Kerja. Left unsolved, or bolted onto a generic "salary" field:

- Statutory rates and brackets (PTKP, TER categories, BPJS percentages/wage caps) change
  almost every year (see e.g. the 2024 TER switch, the 2025/2026 BPJS wage-cap adjustments).
  Hardcoding them means a code deploy every time a regulation changes — unacceptable for a
  solo dev supporting live payroll runs.
- PPh 21 has **two different calculation regimes in the same tax year**: TER (monthly,
  Jan–Nov, using a flat effective rate by PTKP category) and annualized Pasal 17 progressive
  recalculation (December) — get this wrong and the client's employees get audited, not just
  annoyed.
- THR and severance are legally mandated, formula-driven, and time-critical (THR must be paid
  H-7 before the religious holiday; severance formulas differ by termination reason and tenure)
  — this is exactly the kind of thing an Indonesian-market client expects the platform to get
  right without manual spreadsheet gymnastics.
- Every module in the ERP that touches people cost (Legal timesheets/fee-splits, Property
  building staff, future modules) will eventually want payroll — building it generic and
  decoupled now avoids a rewrite later.
- No single place to see "what's this pay run going to cost," "what's owed to the tax office
  and BPJS this month," or "did this payslip actually get paid" — reconciliation is currently
  manual/spreadsheet-based for most SME clients, which is the direct pain point the module
  should remove.

**Client requirements:**
- Must be usable **standalone** — a tenant can run Payroll with nothing else installed
  (own minimal employee master data), same "standalone-capable Core module" posture as DMS
  and Schedule.
- Must follow current Indonesian payroll law: PPh 21 (TER method + December annualization),
  BPJS Kesehatan, BPJS Ketenagakerjaan (JHT, JP, JKK, JKM, JKP), THR, and UU Cipta
  Kerja–compliant severance/termination pay.
- Statutory rates, brackets, and caps must be **tenant-independent, versioned, admin-editable
  configuration** — never hardcoded — because they are set by government regulation, not by
  the tenant, and change on a schedule the platform doesn't control.
- Must support the full payroll lifecycle: regular monthly runs, off-cycle runs, THR runs,
  bonus runs, final/termination pay, and post-run adjustments/corrections.
- Every run must be auditable, lockable once paid, and gated by approval before disbursement
  — payroll is the single most trust-sensitive number in the whole ERP.
- Reuses WNE for approvals and notifications, and DMS for document storage (payslips,
  reimbursement receipts, loan agreements) — no parallel workflow/notification/storage code,
  same rule already applied by every other Core module in this project.

# 2. Goals

> Designated features. MVP-first — ship something correct and sellable fast; defer heavy
> integrations (GL/accounting export, direct bank API, multi-country) to Future Version.

**MVP (ship first — quick implementation, statutory correctness is non-negotiable)**
- **Employee master data** (minimal, Payroll-owned for now — see §5 for the future-HR-module
  migration path): identity, employment status, tax status (PTKP), BPJS enrollment numbers,
  bank account, assigned salary structure and payroll group.
- **Payroll Setup**: Payroll Groups, Payroll Calendars, Salary Structures, Payroll Components
  (earning/deduction definitions), Deduction Rules (loans/advances amortization logic), and —
  critically — **versioned Tax Rules, BPJS Rules, and Regulatory Rules** so a rate change next
  year is a data entry, not a deploy.
- **Payroll Processing**: Payroll Periods, Regular Payroll, Off-Cycle Payroll, THR Payroll,
  Bonus Payroll, Final (termination) Payroll, and Payroll Adjustment — all built on one shared
  calculation engine (§3, Engine 3J) so the six run *types* differ only in configuration/inputs,
  not in six separate codebases.
- **Payroll Inputs**: Variable Earnings, Overtime (Kepmenaker 1/173 formula), Bonus,
  Commission, Reimbursement, Loans, Salary Advances — all feed the same run engine as line
  items.
- **Statutory engines**: PPh 21 (TER monthly + December annualized reconciliation), BPJS
  Kesehatan, BPJS Ketenagakerjaan (JHT/JP/JKK/JKM/JKP), THR (PP 36/2021 formula incl. pro-rata
  for <12 months tenure), Severance (UU Cipta Kerja pesangon/UPMK/UPH tables), Regulatory
  Updates (the admin surface for the versioned rule tables above).
- **Payment**: Bank Payment (bank transfer file export — CSV/Excel per-bank format, MVP is
  file-based, not a live bank API), Payment Batch, Payment Reconciliation (mark paid, capture
  failures for re-run).
- **Reports**: Payslips (PDF, stored via DMS), Payroll Reports (run summary, cost breakdown),
  Tax Reports (monthly PPh 21 recap + annual data formatted for Bukti Potong 1721-A1 /
  Coretax import — see §5), BPJS Reports (contribution recap formatted for BPJS portal
  upload), Audit Reports.
- **Administration**: Payroll Approval (via WNE workflow, not custom logic), Payroll Lock
  (a paid period becomes immutable — corrections go through Payroll Adjustment, never an
  in-place edit), Audit Trail (append-only, mirrors DMS's `access_logs` pattern), Security
  (payroll data visibility is a distinct, stricter permission tier than general ERP access —
  see §5).

**Future Version (post-launch, once there's real usage/revenue to justify the build)**
- **Accounting/GL export** — journal-ready entries per payroll run, once a Finance/Accounting
  Core module exists to receive them. Payroll shouldn't invent GL logic prematurely.
- **Direct bank disbursement API** integration (per bank), replacing the MVP file-export flow.
  Naturally a justified extraction candidate later (external API integration, per-bank
  variance) — file export is the right MVP because it works with *any* bank today.
- **e-SPT / Coretax direct API submission** — MVP produces correctly formatted export data;
  direct API filing is a fast-follow once Coretax's integration API stabilizes for
  third-party payroll systems.
- **Employee self-service portal** (view own payslips, submit reimbursements/leave) — v1 is
  admin/HR-operated; self-service is a UX layer on the same data, not a schema change.
- **Multi-country/multi-currency payroll** — out of scope; this module is Indonesia-first by
  design (PTKP/TER/BPJS/THR/severance are all Indonesia-specific), but the rule-versioning
  architecture (§5) is what would let a second country's rules be added without a rewrite.
- **Time & Attendance integration** (clock-in/out driving overtime automatically) — v1 accepts
  overtime as a manual/imported input; a future Attendance module would publish events Payroll
  consumes, same decoupled pattern as everything else.
- **Benefits administration** (insurance beyond BPJS, allowances-in-kind) beyond what fits in
  Payroll Components today.

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard

**Function / features**
- At-a-glance payroll health: current period status (open/processing/approved/paid/locked),
  headcount in current run, total gross/net/employer-cost for the period, statutory liability
  summary (PPh 21 withheld, BPJS employer + employee contributions due).
- "Needs attention" queue: runs pending approval, THR due within N days, loan installments
  failing to deduct (insufficient net pay), payment batch failures.
- Quick actions: start a new run, open current period, jump to Regulatory Updates if a rule's
  effective date is approaching/expired.

**Layout**
- Top: 4 summary cards — Employees in Current Run, Total Net Pay (current period), Pending
  Approvals, Upcoming THR/Statutory Due Dates.
- Main: tabbed table — "Active Runs" | "Pending Approval" | "Payment Batches" | "Alerts".
- Each row uses the shared **Status Rail** (per `DESIGN.md`) — `info` = draft/processing,
  `warning` = pending approval or due soon, `success` = paid/completed, `danger` = failed
  payment, rule expiring, or negative net pay.

**Rules / logic**
- Tenant-scoped automatically (DB-per-tenant — no `tenant_id` column, per `CLAUDE.md` §7,
  same convention as Schedule/DMS/CRM).
- Payroll dashboard visibility itself is gated by the stricter Payroll security tier (§5) —
  this dashboard is not visible to a general ERP user by default.

## 3B. Payroll Setup — Payroll Groups

**Purpose:** a Payroll Group is the unit a Payroll Run processes — typically one legal entity
or one pay-frequency cohort (e.g. "Monthly Staff — PT ABC", "Daily Workers — PT ABC").

- Fields: name, legal entity reference (free-text/lookup, no separate Legal-Entity module yet),
  default Payroll Calendar, default Salary Structure (optional), default JKK risk category
  (see 3-BPJS), active flag.
- Every Employee belongs to exactly one Payroll Group at a time (history kept on reassignment).

## 3B. Payroll Setup — Payroll Calendars

- Fields: name, pay frequency (monthly / semi-monthly / weekly / daily), cutoff-day rule
  (e.g. "26th of prior month to 25th of current month"), pay date rule (e.g. "last working day
  of month", with a public-holiday-aware "shift earlier" flag — reads Schedule's calendar if
  installed, otherwise a simple weekday check).
- Generates `payroll_periods` rows ahead of time (e.g. 12 months forward) so setup happens once
  a year, not once a period.

## 3B. Payroll Setup — Salary Structures

- A Salary Structure is a named template of Payroll Components with default amounts/formulas
  (e.g. "Staff Grade 2": Basic Salary fixed + Position Allowance fixed + Transport Allowance
  fixed + BPJS/PPh21 auto-included by the component's own type).
- Assigned to an Employee (or a Payroll Group as a default); per-employee overrides are allowed
  at the individual component-assignment level without forking the whole structure.

## 3B. Payroll Setup — Payroll Components

**Purpose:** the atomic building block of every payslip — every earning and every deduction,
statutory or not, is a Payroll Component.

- Fields: code, name, type (`earning` / `deduction`), category (`fixed` / `formula` /
  `statutory` / `variable-input`), calculation basis (flat amount, % of a named base component
  e.g. "% of Basic Salary", or a reference to a statutory engine — PPh21 / BPJS Kesehatan /
  BPJS Ketenagakerjaan), taxable flag (does this component count toward PPh 21 gross?),
  BPJS-basis flag (does it count toward BPJS contribution base?), GL-account placeholder
  (nullable, for Future Version accounting export), is_active.
- Statutory components (PPh 21, BPJS Kesehatan, BPJS Ketenagakerjaan) are **system-defined,
  read-only** rows that delegate their actual number to the relevant statutory engine (§3
  Statutory) rather than storing a formula here — this is what keeps a rate change a data
  change in the Regulatory Rules tables, not a Payroll Component edit.

## 3B. Payroll Setup — Deduction Rules

- Configuration for non-statutory deductions with a repeating/amortizing shape: Loans (term
  months, interest method — flat or none, MVP doesn't need compound), Salary Advances
  (single-installment or short-term split), and any tenant-defined recurring deduction (e.g.
  cooperative/koperasi dues).
- Defines the **installment behavior** consumed by 3-Loans/3-Advances below: how a monthly
  installment is calculated and what happens if net pay is insufficient (skip and roll forward
  vs. partial deduction — tenant-configurable, defaults to skip-and-roll-forward with a flag
  raised on the dashboard).

## 3B. Payroll Setup — Tax Rules (PPh 21)

**Purpose:** the versioned, admin-editable source of truth for PPh 21 — this is what lets the
platform absorb a regulation change (rate table, PTKP amount, bracket) as a new row with an
`effective_date`, never a code deploy.

- `ptkp_statuses`: lookup (`TK/0`, `TK/1`, `TK/2`, `TK/3`, `K/0`, `K/1`, `K/2`, `K/3`, ...) —
  annual PTKP amount per status, versioned by `effective_date` (PTKP has changed by government
  regulation before and will again).
- `ter_categories`: TER Category A / B / C, each mapped to the PTKP statuses that fall into it
  per PMK 168/2023 (Category A: TK/0, TK/1, K/0; Category B: TK/2, TK/3, K/1, K/2; Category C:
  K/3), versioned.
- `ter_rate_brackets`: per category, monthly gross-income brackets → effective rate (%),
  versioned by `effective_date` — this is the table actually used for the Jan–Nov monthly
  withholding.
- `ter_daily_rates`: the daily-effective-rate brackets (0% up to Rp450,000/day, 0.5% above,
  per current rules) for non-permanent/daily workers, versioned.
- `pph21_progressive_brackets`: the annual Pasal 17 progressive brackets (5/15/25/30/35% per
  current law), versioned — used for the December annualized recalculation and for
  irregular-income items (bonus, THR, severance) that use the annualization method rather than
  monthly TER.
- Every table above carries `effective_date` + `is_active`; the calculation engine (§3-PPh21)
  always resolves "which version applies to this period" rather than assuming the latest row.

## 3B. Payroll Setup — BPJS Rules

**Purpose:** same versioned pattern as Tax Rules, for both BPJS programs.

- `bpjs_kesehatan_rules`: employer %, employee %, wage cap (Rp), versioned by `effective_date`.
- `bpjs_ketenagakerjaan_rules`: one row per sub-program (JHT, JP, JKK, JKM, JKP) × versioned
  `effective_date`, each with employer % and employee % (JKK/JKM/JKP are employer-only per
  current rules, but the schema doesn't assume that — it's data, not code) and a wage cap
  where applicable (notably JP).
- `jkk_risk_categories`: lookup of JKK risk tiers (very-low through very-high) with their
  associated employer contribution %, assigned per Payroll Group or per Employee (some tenants
  mix office staff and field staff under different risk categories).

## 3B. Payroll Setup — Regulatory Rules (Regulatory Updates admin surface)

- A single admin screen surfacing every versioned rule table above (Tax Rules + BPJS Rules) in
  one place, with a "new regulation" workflow: enter the new version + effective date, preview
  its impact on a sample payroll before it goes live, and see a diff against the currently
  active version.
- Optional: route a new regulatory version through a WNE approval workflow before it activates
  — for a tenant that wants a second pair of eyes before a rate change goes live on real payroll
  (`workflow_code = payroll.regulatory_rule_activation`).

## 3C. Payroll Periods

- One row per Payroll Group × Payroll Calendar cycle: period start/end, cutoff date, scheduled
  pay date, status (`open` → `processing` → `approved` → `paid` → `locked`).
- A period only accepts new Regular Payroll runs while `open`; once `locked` (see 3-Admin
  Payroll Lock), the only path to change anything is a Payroll Adjustment run referencing the
  locked period.

## 3D. Regular Payroll (Run Engine)

**Function / features**
- Select Payroll Group + Payroll Period → the run engine pulls: each active employee's Salary
  Structure, any Payroll Inputs logged against this period (overtime, variable earnings, bonus,
  commission, reimbursement), outstanding loan/advance installments due, then computes
  statutory deductions via the PPh 21 / BPJS engines, producing one `payroll_run_line` per
  employee (= draft payslip).
- Reviewable before submission: a line-item table per employee, editable at the input level
  (not by hand-overriding computed statutory numbers) before submit-for-approval.

**Rules / logic**
- Every run is `draft` until submitted; submission fires a WNE `WorkflowRequested`
  (`workflow_code = payroll.run_approval`) rather than Payroll implementing its own approval
  chain — same reuse pattern as every other module.
- Net pay < 0 (deductions exceed earnings) blocks submission with a clear per-employee error,
  per `DESIGN.md` voice guidance, rather than silently producing a negative payslip.

## 3E. Off-Cycle Payroll

- Same run engine as Regular Payroll, but not tied to the normal calendar period — for
  corrections needed *before* the next normal run, one-off payments, or a subset of employees
  (e.g. new hires needing an immediate advance pay). Requires a reason code.

## 3F. THR Payroll (Tunjangan Hari Raya)

**Purpose:** the statutory religious holiday allowance (PP 36/2021) — mandatory, formula-driven,
time-critical (due no later than H-7 before the relevant religious holiday).

- Formula: employees with ≥12 months continuous tenure get 1× monthly salary (Basic + fixed
  allowances per the tenant's THR-basis component flag); employees with <12 months tenure get
  a pro-rata amount (`months of service / 12 × monthly salary`).
- `thr_calculations` stores the tenure snapshot, formula inputs, and result per employee —
  auditable independent of the run itself, since THR compliance is frequently inspected.
- THR is taxed under the **annualization method** (irregular income), not monthly TER — the
  PPh 21 engine (§3-PPh21) exposes a distinct calculation mode for this.
- A scheduled reminder (via WNE) fires ahead of each configured religious holiday date so the
  THR run isn't started too late to hit the H-7 deadline.

## 3G. Bonus Payroll

- Same run engine, for discretionary/performance bonuses. Like THR, taxed via the
  annualization method rather than monthly TER (irregular income treatment).
- Supports a simple per-employee amount entry or a bulk import (CSV) for larger bonus pools —
  bulk import is the MVP path to avoid building a full performance-management module.

## 3H. Final Payroll (Termination)

**Purpose:** last payslip for a terminated employee — final regular pay, unused leave
encashment if configured, and statutory severance where applicable.

- Fields: termination date, termination reason (resignation / termination-with-cause /
  redundancy / retirement / contract-end / death — the reason drives which severance formula
  applies per UU Cipta Kerja / PP 35/2021).
- `severance_calculations`: computes Uang Pesangon (severance pay), Uang Penghargaan Masa
  Kerja (UPMK, service recognition pay), and Uang Penggantian Hak (UPH, compensation of
  rights) per the tenure-based multiplier tables in PP 35/2021, driven off a versioned
  `severance_rule_tables` (same rationale as Tax/BPJS Rules — these tables are set by
  regulation, not the tenant).
- Final PPh 21 on severance uses its own statutory treatment (final/separate rate bands per
  current regulation) rather than the regular TER or annualization method — the PPh21 engine
  exposes a third calculation mode for this.
- On completion: employee status flips to `terminated`, BPJS de-registration reminder fires via
  WNE, and the employee stops appearing in future Payroll Group runs.

## 3I. Payroll Adjustment

- The **only** legitimate path to change a `paid`/`locked` period's numbers: create an
  Adjustment run referencing the original run + affected employee(s) + reason, producing a
  delta payslip (positive or negative) rather than mutating history — mirrors DMS's
  "never overwrite, always version" principle.
- Adjustments feed the same statutory engines (an adjustment to taxable income re-triggers a
  PPh 21 delta calculation, not a manual override) so year-end tax recap stays correct.

## 3J. Payroll Run Calculation Engine (shared by 3D–3I)

**Purpose:** the one engine all six run types call — keeps the six *processes* thin
configuration/entry points rather than six parallel calculators.

`PayrollRunEngine::calculate(runId)`:
1. Resolve applicable versioned rule sets (Tax Rules, BPJS Rules, Severance Rules if
   applicable) as of the run's period date — never "whatever's latest," always
   "whatever was effective on this date," so a mid-year regulation change never
   retroactively alters an already-paid period.
2. Gather earning components (structure defaults + Payroll Inputs for the period).
3. Gather deduction components (structure defaults + due loan/advance installments +
   Deduction Rules).
4. Compute BPJS Kesehatan + BPJS Ketenagakerjaan contributions (employee + employer split)
   against each program's own wage-cap rule.
5. Compute PPh 21 in the mode appropriate to the run type (monthly TER for Regular/Off-Cycle;
   annualized for THR/Bonus; final/separate rate for Final Payroll/severance; December
   annualized reconciliation for the last Regular run of the calendar year).
6. Produce `payroll_run_lines` + `payroll_run_line_components` (full line-item breakdown, the
   source data for the Payslip PDF).

## 3K. Payroll Inputs — Variable Earnings, Overtime, Bonus, Commission, Reimbursement

- **Variable Earnings**: free-form recurring/one-off earning entries not covered by fixed
  Salary Structure components (e.g. shift differential), tagged to a period.
- **Overtime**: hours × rate, computed per the Kepmenaker formula basis (hourly rate =
  1/173 × monthly wage, with statutory multipliers for weekday/weekend/holiday overtime) —
  the multiplier table itself lives in a small versioned lookup, same rule-versioning
  discipline as the other statutory tables.
- **Bonus / Commission**: entered here as *inputs* consumed by Bonus Payroll (3G) or added to
  a Regular run if the tenant treats them as ordinary monthly income instead.
- **Reimbursement**: request → approve (via WNE) → pay-out as a non-taxable earning line;
  receipt/proof attaches via **DMS** (`DocumentService::attach()`), not a parallel upload —
  same integration pattern DMS already exposes to every other module.
- **Loans / Salary Advances**: see 3B Deduction Rules for configuration; `employee_loans` +
  `loan_installments` track principal, remaining balance, and per-period auto-deduction;
  `salary_advances` is the lighter-weight single/short-term variant. Loan agreement documents
  also attach via DMS.

## 3-PPh21. PPh 21 Engine (Statutory)

- `calculateMonthlyTER(employee, grossIncome, period)`: resolves the employee's PTKP status →
  TER category (A/B/C) → applicable bracket from `ter_rate_brackets` as of the period date →
  withholding = grossIncome × rate. This is the Jan–Nov monthly mode.
- `calculateAnnualizedReconciliation(employee, taxYear)`: run automatically as part of the
  December Regular Payroll run — sums the year's actual gross income, applies
  `pph21_progressive_brackets` + the employee's annual PTKP to get the *true* annual tax,
  compares against TER withheld Jan–Nov, and posts the difference (under/over-withheld) as the
  December PPh 21 line. This is the mechanism the regulation actually requires (TER
  simplifies monthly withholding; it doesn't replace the annual liability).
- `calculateIrregularIncome(employee, amount, incomeType)`: annualization-method calculation
  used by THR (3F) and Bonus (3G) runs.
- `calculateFinalSeverance(employee, severanceAmount)`: separate/final-rate calculation used
  by Final Payroll (3H).
- `pph21_calculations`: one row per employee per period per calculation mode — the audit trail
  and the source for Tax Reports (§3 Reports) and the annual Bukti Potong 1721-A1 export.

## 3-BPJS. BPJS Engines (Statutory)

- `BpjsKesehatanEngine::calculate(employee, wageBase, asOfDate)`: applies the versioned
  employer/employee % against `min(wageBase, wageCap)` from `bpjs_kesehatan_rules`.
- `BpjsKetenagakerjaanEngine::calculate(employee, wageBase, asOfDate)`: applies each
  sub-program (JHT, JP, JKK, JKM, JKP) from `bpjs_ketenagakerjaan_rules`, using the employee's
  assigned JKK risk category, each against its own wage cap where one exists (notably JP).
- Both engines write to `bpjs_kesehatan_contributions` / `bpjs_ketenagakerjaan_contributions`
  (per employee, per period, employer + employee amounts split out) — this is the direct
  source for the BPJS Reports export (§3 Reports).

## 3-Payment. Bank Payment / Payment Batch / Payment Reconciliation

- **Payment Batch**: groups one or more approved/paid `payroll_run_lines` into a disbursement
  batch (typically one per Payroll Group per period, but supports partial/split batches for
  multi-bank scenarios).
- **Bank Payment**: exports a batch to a bank-specific file format (CSV/Excel, per
  `bank_master` template) — MVP is file-based (upload to internet banking manually or via the
  bank's own bulk-transfer portal), not a live API integration (see §2 Future Version).
- **Payment Reconciliation**: mark each batch line `paid` / `failed` (manual entry MVP, or bulk
  import of the bank's status-return file if the bank provides one), with `failed` lines
  surfaced on the Dashboard and re-batchable without re-running payroll calculation.

## 3-Reports. Reports

- **Payslips**: per-employee PDF, generated from `payroll_run_lines` +
  `payroll_run_line_components`, stored via **DMS** (`subject_type = 'payroll.run_line'`) so
  version history/audit/retention are inherited for free rather than rebuilt — same reuse
  discipline as everywhere else in this project.
- **Payroll Reports**: run summary, cost-by-Payroll-Group, cost trend over time.
- **Tax Reports**: monthly PPh 21 withholding recap (for e-Bupot/Coretax filing) and annual
  employee tax data formatted to match the Bukti Potong 1721-A1 layout — MVP produces correctly
  structured export data; direct Coretax API submission is Future Version (§2).
- **BPJS Reports**: monthly contribution recap per program, formatted for upload to the BPJS
  Kesehatan/Ketenagakerjaan employer portals.
- **Accounting Reports**: placeholder in MVP (a per-run cost/liability summary export, CSV) —
  full GL journal export is Future Version, pending a Finance/Accounting Core module to receive
  it (see §2).
- **Audit Reports**: renders `payroll_access_logs` (3-Admin) with filters by employee, run,
  actor, action — the compliance-facing report for "who saw/changed what."

## 3-Admin. Administration — Payroll Approval, Payroll Lock, Audit Trail, Security

- **Payroll Approval**: not a custom engine — every run submission is a WNE
  `WorkflowRequested` (`workflow_code = payroll.run_approval`, with distinct codes per run
  type if a tenant wants different approval chains for e.g. Final Payroll vs. Regular). Payroll
  never implements its own approval state machine, per the project-wide WNE-reuse rule.
- **Payroll Lock**: once a run is marked `paid`, its `payroll_period` (and every
  `payroll_run_line` in it) flips `locked` — no direct edit is possible at the app layer;
  the only path forward is a Payroll Adjustment (3I) referencing the locked period.
- **Audit Trail**: `payroll_access_logs` — append-only, one row per action (`view_payslip`,
  `run_created`, `run_submitted`, `run_approved`, `run_paid`, `run_locked`, `adjustment_created`,
  `regulatory_rule_changed`, `employee_salary_changed`, ...), actor, timestamp, subject
  reference — same immutable-audit posture as `dms.access_logs`.
- **Security**: Payroll introduces a **stricter permission tier** than the general ERP RBAC —
  "can see this module exists" ≠ "can see any employee's salary" ≠ "can see all employees'
  salaries" ≠ "can approve a run" ≠ "can edit Regulatory Rules." MVP implements this as a small
  fixed set of Payroll-specific roles (Payroll Viewer / Payroll Operator / Payroll Approver /
  Payroll Admin) layered on top of whatever general auth the platform uses, rather than a
  full custom ACL engine — matches the MVP bias already used elsewhere (DMS's folder-level flag
  instead of per-document ACL).

---

# 4. Storage

**Database (schema `PAYROLL`, tenant DB — consistent with `CLAUDE.md` §7A; no `tenant_id`
column, isolation is the database boundary):**

**Master / setup tables**
- `PAYROLL.employees` — minimal employee master (see §5 for future HR-module migration path):
  name, employment status, join/termination dates, PTKP status ref, BPJS numbers, payroll
  group ref, salary structure ref, JKK risk category ref.
- `PAYROLL.employee_bank_accounts` — bank, account number, account holder name, primary flag.
- `PAYROLL.payroll_groups`
- `PAYROLL.payroll_calendars`
- `PAYROLL.salary_structures`
- `PAYROLL.salary_structure_components` — pivot: structure × component × default amount/formula.
- `PAYROLL.payroll_components` — earning/deduction definitions (see 3B).
- `PAYROLL.grades` — optional simple job-grade lookup, referenced by Salary Structures.
- `PAYROLL.deduction_rule_configs` — Loans/Advances/recurring-deduction behavior config.
- `PAYROLL.loan_types`
- `PAYROLL.reimbursement_categories`
- `PAYROLL.bank_master` — bank payment file-format templates.
- `PAYROLL.jkk_risk_categories`

**Versioned statutory rule tables (§3B Tax/BPJS/Regulatory Rules)**
- `PAYROLL.ptkp_statuses`
- `PAYROLL.ter_categories`
- `PAYROLL.ter_rate_brackets`
- `PAYROLL.ter_daily_rates`
- `PAYROLL.pph21_progressive_brackets`
- `PAYROLL.overtime_multiplier_rules`
- `PAYROLL.bpjs_kesehatan_rules`
- `PAYROLL.bpjs_ketenagakerjaan_rules`
- `PAYROLL.severance_rule_tables` — UU Cipta Kerja / PP 35/2021 pesangon/UPMK/UPH multipliers.
- All of the above carry `effective_date` + `is_active`; none are ever hard-deleted (a
  superseded version stays for historical run recalculation/audit).

**Transaction / run tables**
- `PAYROLL.payroll_periods`
- `PAYROLL.payroll_runs` — header: type (`regular`/`off_cycle`/`thr`/`bonus`/`final`/
  `adjustment`), payroll group, period, status, `workflow_instance_id` (nullable, informational
  reference into WNE, per the `subject_type`/`subject_id` seam pattern already used everywhere
  else — Payroll doesn't foreign-key into WNE's schema).
- `PAYROLL.payroll_run_lines` — per-employee payslip header: gross, total deductions, net,
  employer cost.
- `PAYROLL.payroll_run_line_components` — line-item breakdown per component.
- `PAYROLL.pph21_calculations` — audit detail per employee/period/mode (TER / annualized /
  irregular / final-severance).
- `PAYROLL.bpjs_kesehatan_contributions`
- `PAYROLL.bpjs_ketenagakerjaan_contributions` — one row per sub-program (JHT/JP/JKK/JKM/JKP)
  per employee per period.
- `PAYROLL.thr_calculations`
- `PAYROLL.severance_calculations`
- `PAYROLL.overtime_entries`
- `PAYROLL.variable_earning_entries`
- `PAYROLL.commission_entries`
- `PAYROLL.reimbursement_requests`
- `PAYROLL.employee_loans`
- `PAYROLL.loan_installments`
- `PAYROLL.salary_advances`
- `PAYROLL.payment_batches`
- `PAYROLL.payment_batch_lines`
- `PAYROLL.payment_reconciliations`
- `PAYROLL.payroll_access_logs` — audit trail, append-only (no update/delete at the app layer,
  same rule as `dms.access_logs`).

**Object File (per `CLAUDE.md` §7B):**
```text
tenant_001/PAYROLL/
├── payslips/{payroll_run_line_id}/v{n}.pdf          # via DMS, subject_type = payroll.run_line
├── reimbursements/{reimbursement_request_id}/       # receipts, via DMS
└── loans/{employee_loan_id}/                        # loan agreements, via DMS
```
- Payroll does not implement its own object storage path — every file lives under DMS with
  the appropriate `subject_type`/`subject_id`, inheriting versioning/retention/audit for free.

# 5. Technical Notes

> All necessary technical detail to help AI Coding.

**Architecture pattern:** Core module, same monolithic-modular posture and internal shape as
WNE/DMS/CRM/Schedule (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`,
`Routes/` under `app/Modules/Payroll/`). No microservice extraction at MVP — payroll
calculation is CPU-light, synchronous-enough batch work, not a candidate under `CLAUDE.md` §2's
extraction criteria. Revisit only if/when PDF payslip generation at very high volume becomes a
throughput problem (same reasoning already applied to DMS's OCR).

**Why Employees live in Payroll for now, not a separate HR module:** there is no dedicated HR
module yet, and payroll cannot function without *some* employee master data. Payroll owns a
deliberately minimal `employees` table (identity, employment status, tax/BPJS status, bank,
structure assignment) — enough to run payroll, nothing more (no performance reviews, no leave
management, no org chart). When a dedicated HR module is eventually built, it becomes the
system of record and Payroll's `employees` table is refactored to reference it (Vertical/
sibling-Core → Core direction, same as CRM's Partner registry), rather than Payroll trying to
be an HR module today.

**Why statutory rates are data, not code — the core architectural decision of this module:**
PTKP, TER categories/brackets, BPJS percentages/wage caps, overtime multipliers, and severance
multipliers are all set by Indonesian government regulation on a schedule outside the
platform's control (PTKP and BPJS caps have both changed within the last two years). Every one
of these lives in a versioned table (`effective_date` + `is_active`), resolved by the
calculation engine "as of" the relevant date — never hardcoded in a Service class, and never
mutated in place (a new regulation is a new row, old rows stay for historical recalculation).
This is what makes "Indonesian regulatory compliance" a sustainable feature for a solo dev
instead of an annual fire-drill deploy — and it's a genuine marketability point: "rates update
without waiting on a software release" is a real answer to a buyer's "what happens when the tax
rules change" question.

**PPh 21 — three distinct calculation modes, one engine:** monthly TER (Jan–Nov regular),
December annualized Pasal 17 reconciliation, and irregular-income annualization (THR/bonus) —
plus a fourth, final/separate-rate mode for severance. Get the *mode selection* right per run
type; this is the single highest-compliance-risk piece of the module and the one place worth
the most test coverage.

**BPJS wage caps apply per-program, not once:** JP has its own cap distinct from (and typically
lower than) any cap BPJS Kesehatan applies; JHT/JKK/JKM in current rules have no cap. Model the
cap on `bpjs_*_rules` per sub-program, not as a single "BPJS wage cap" field.

**Cross-module integration (decoupled, event-driven — same seam as every other Core module):**
- **WNE**: `WorkflowRequested` for every run approval and any Regulatory Rule activation a
  tenant wants gated; `NotificationRequested` for payslip-ready notices, THR due-date
  reminders, and loan-installment-failed alerts. Payroll implements zero approval or
  notification logic itself.
- **DMS**: `DocumentService::attach()` for payslips, reimbursement receipts, and loan
  agreements. Payroll implements zero file storage/versioning logic itself.
- **Schedule** (optional, if installed): Payroll Calendars can read Schedule's public-holiday
  data to shift a pay date earlier when it lands on a holiday; Payroll must not throw if
  Schedule is absent for a tenant — same feature-flag-safe posture Schedule itself uses toward
  WNE.
- **CRM**: not integrated — employees are not Partners; keep these concepts separate rather
  than forcing them into one table, matching CRM's own guidance on when *not* to collapse two
  distinct entities.

**Queues:** Payroll run calculation for a large Payroll Group can be dispatched to a queued
job (`payroll` queue) to avoid a long synchronous request, but the calculation itself is
deterministic/idempotent (safe to re-run against the same inputs) — this matters because a
mid-calculation regulatory-rule change must never partially apply to a run already in progress
(resolve rule versions once, at run start, and hold them for the whole run).

**IDs:** `BIGSERIAL` for all internal PKs/FKs per `CLAUDE.md` §7; add UUID on `employees` and
`payroll_run_lines` for future external-facing use (employee self-service portal, per §2 Future
Version), mirroring CRM's `uuid` rationale.

**Custom fields:** `employees`, `payroll_components`, and `payroll_runs` register against the
existing `CUSTOMFIELDS` schema (per `CLAUDE.md` §7A) — a tenant-specific field (e.g. "Cost
Center") never requires a Payroll migration.

**MVP scope boundary (be explicit about what's deferred, per §2):**
- No GL/accounting export beyond a flat CSV cost summary — full journal entries wait for a
  Finance/Accounting Core module.
- No live bank disbursement API — file export only.
- No employee self-service UI — admin/HR-operated screens only; the data model already
  supports self-service as a Future Version UI layer without a schema change.
- No Time & Attendance integration — Overtime is a manual/imported input row.

**Suggested build order for Claude Code:** 3B (Setup: Groups, Calendars, Components, Salary
Structures) → versioned Tax/BPJS Rule tables + seed current-year data → 3-PPh21 + 3-BPJS
engines in isolation with unit tests against known example calculations → 3C/3D (Periods +
Regular Payroll run engine, 3J) → 3-Payment (batch + file export) → 3-Reports (Payslips via
DMS) → 3-Admin (Approval via WNE, Lock, Audit Trail) → 3F/3G/3H (THR, Bonus, Final — reuse 3J)
→ 3I (Adjustment) → 3K remaining inputs (Loans, Advances, Reimbursement via DMS) — ship at this
point — then revisit Future Version items (GL export, bank API, self-service) once there's
real tenant usage to justify them.
