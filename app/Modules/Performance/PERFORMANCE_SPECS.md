# Performance Module
## Performance Management Engine — Core Shared Module (standalone-capable)

# 1. Backgrounds

> Pain point and business value.

Every organization eventually needs to answer three questions: *where did we plan to be, where
are we actually, and where are we heading?* Today that lives in scattered spreadsheets — a
budget workbook, a separate KPI tracker, an OKR slide deck refreshed manually each quarter, a
forecast nobody reconciles against actuals. Left unsolved centrally, this repeats the same
anti-pattern every other Core module in this platform was built to avoid:

- Each department/vertical invents its own "how are we doing" tracking — no shared metric
  definitions, no comparable rollups across teams, no single source of truth for "what counts
  as on-target."
- Targets, budgets, and OKRs live in different places with no common way to see variance
  (planned vs. actual) at a glance.
- No place to roll individual/team performance up into a company scorecard, or cascade a
  company-level objective down into team/individual objectives.
- No shared notion of "this metric breached its target" that other modules (WNE) can act on —
  every module that wants a performance alert re-invents threshold-checking.
- Recognition (hitting a target, completing an OKR) has no system of record — it's tribal
  knowledge or a Slack message, not something reportable.

**Client requirements:**
- Multi-tenant aware, same posture as every other Core module.
- Must work **standalone** — a tenant can run Performance with nothing else installed (manual
  data entry for actuals), and it must also work **attached** to any other module's records
  (a Legal case's billable-hours KPI, a Sales team's revenue target, a Property portfolio's
  occupancy rate) via the same polymorphic seam used everywhere else in this codebase.
- KPIs and OKRs must support **multiple levels** — company, division, department, team,
  individual — with the ability to see how a lower-level metric/objective rolls up into (or
  aligns with) a higher-level one.
- Budgeting, Targets, and Forecasts must all be comparable against Actuals through one shared
  **Variance Analysis** engine — variance logic should not be reimplemented per feature.
- **Performance's Budgeting is deliberately not a duplicate of Accounting's Budgeting**
  (`ACCOUNTING_SPECS.md` §3J) — they answer different questions. Accounting's budget is
  GL-account × cost-center-scoped and feeds statutory/financial reporting, with actuals
  sourced directly from posted journals (finance-grade, audit-precise). Performance's budget
  is subject-based (company/department/team/individual, or any vertical record) and
  category-based (a free label like "Marketing," not necessarily a single GL account),
  designed to sit alongside KPIs and OKRs in one Scorecard — a management/board view, not a
  second GL. When Accounting is installed, Performance's Variance Engine can optionally
  resolve a budget category's "actual" from Accounting's GL data (§3B/§3G) instead of
  requiring manual entry — but Performance never becomes a second ledger, the same "one
  ledger, many requesters" discipline used everywhere else in this platform.
- Must integrate with WNE for threshold-breach notifications and optional approval workflow
  (e.g. budget approval) — Performance does not build its own notification or approval logic.
- Must integrate with Schedule for periodic review cadence (e.g. "OKR check-in due weekly") —
  Performance does not build its own scheduling logic.
- Must be sellable as a standalone add-on (an "Executive Dashboard" story) as well as a
  natural upsell once a tenant already has data flowing through other Core modules.

# 2. Goals

> Designated features. MVP-first — ship something demoable/sellable fast, defer heavy
> BI/statistical/gamification work.

**MVP (quick implementation)**
- **KPI Library & Multi-Level Assignment.** A tenant-defined library of KPI metrics
  (`perf.kpi_definitions` — name, unit, direction "higher is better" / "lower is better",
  perspective). Any KPI can be *assigned* to any subject at any level (company, department,
  team, individual, or a vertical record like a Legal case) via the standard `subject_type` /
  `subject_id` polymorphic pattern already used by WNE/DMS/CRM/Schedule — "multi-level" is
  achieved by tagging the subject, not by building a separate hierarchy engine.
- **Targets.** A lightweight target-setting form: pick a KPI + subject + period, set target
  value (and optional stretch value). Reuses the same period model as Budgeting/Forecast.
- **KPI Actuals Capture.** MVP = manual entry (a simple "enter this period's number" form),
  one row per KPI/subject/period. Designed so a future automated feed (e.g. pulling a real
  number from CRM/DMS/Legal) is just another writer into the same `kpi_values` table — no
  schema change needed later.
- **OKRs (multi-level, aligned).** Objectives with Key Results underneath (each KR has a
  start/current/target value or a boolean/milestone flag). An Objective can optionally point
  at a `parent_okr_id` — the same self-referencing pattern CRM uses for
  `parent_partner_id` — so a Team OKR can align under a Department OKR under a Company OKR,
  without a rigid forced hierarchy table.
- **Budgeting.** Simple header + line budgets (by category, by period slice — typically
  monthly within a fiscal year), status flow `draft → submitted → approved → locked`. Approval
  can optionally route through a WNE Workflow (`workflow_code = performance.budget_approval`)
  — Performance doesn't implement approval logic itself. A budget category can optionally map
  to one or more Accounting GL accounts (§3B) so its "actual" figure is sourced from real
  posted transactions when Accounting is installed, rather than always requiring manual entry
  — see §3B/§3G and the split explained in §1.
- **Forecast.** A rolling forecast is just a second, editable "projected" line series per
  period slice, versioned so history isn't lost when a forecast is revised — same shape as a
  Budget line, deliberately, so Variance Analysis can treat Budget/Target/Forecast uniformly
  against Actuals.
- **Variance Analysis Engine.** One reusable service — given a subject + KPI/budget-line +
  period — returns `actual vs. plan` (plan being Target, Budget, or Forecast, whichever
  applies), absolute variance, percent variance, and a status classification
  (`on-track` / `warning` / `breach`) using the KPI's "higher/lower is better" direction. This
  single engine powers the Dashboard, Scorecards, and WNE breach notifications — never
  reimplemented per feature.
- **Scorecards.** A configurable set of KPIs/OKRs grouped by **perspective** (Financial,
  Customer, Process, Learning & Growth — tenant-editable, classic Balanced-Scorecard style)
  for a given subject + period, each item weighted, producing a simple weighted score and a
  Status Rail per item and per perspective.
- **Achievements.** Lightweight recognition log — a badge/definition (e.g. "Target Hit,"
  "OKR Completed," "3 Quarters On-Track Streak") automatically logged when the Variance Engine
  or OKR completion crosses a defined rule, or awarded manually by a manager. No leaderboard/
  gamification UI in MVP — just a factual, auditable achievement record other modules (or a
  future HR module) can read.
- **Dashboard.** One rollup screen: scorecards, KPI/OKR status counts, budget-vs-actual
  summary, and "items needing attention" (anything the Variance Engine flagged `warning` or
  `breach`), filterable by period and by subject/level.

**Future Version (post-launch, once there's real usage volume/revenue to justify the build)**
- **Automated KPI data connectors** — scheduled pulls from other modules/external systems
  (e.g. auto-computing a "billable utilization" KPI from DMS/Legal time entries) instead of
  manual entry. Each connector is additive per KPI, doesn't change the core schema.
- **Statistical/predictive forecasting** — trend-line, seasonality, or ML-assisted forecast
  generation, beyond MVP's manual/rolling forecast entry.
- **Multi-currency budgeting** with FX conversion for consolidated rollups.
- **Cascading OKR auto-rollup math** — automatically computing a parent Key Result's progress
  as a weighted function of its children's progress (MVP requires each level to be updated
  independently, which is simpler and avoids a whole class of aggregation edge cases).
- **What-if / scenario planning** — cloning a forecast/budget into alternate scenarios for
  comparison.
- **Gamification layer** — leaderboards, points, streak badges with visual flourish (MVP
  Achievements stays a plain factual log, not a game layer).
- **Drill-down BI / custom report builder** — ad hoc pivoting beyond the fixed Dashboard and
  Scorecard views. (Note: this is also a natural fit for **AIInsights Core**, the tenant-facing
  "ask your data" feature already designed — Performance's structured tables are good candidate
  schema context for it once both ship.)
- **External benchmark import** (industry KPI benchmarks for comparison).

# 3. Forms / Engines

> Every Form and Engine (Entry, View, Report, Service) — layout, logic, business rules, DB design.

## 3A. Main Dashboard

**Function / features**
- Rollup view: perspective scorecards, KPI/OKR status counts (on-track / warning / breach),
  budget-vs-actual summary strip, "needs attention" list (everything the Variance Engine
  flagged non-`on-track`), recent Achievements feed.
- Filters: period, subject/level (company / department / team / individual / a specific
  vertical record), perspective.

**Layout**
- Top: summary cards — Overall Scorecard %, Budget Variance %, OKRs On-Track (count/total),
  Open Breaches.
- Main: tabbed — "Scorecards" | "KPIs" | "OKRs" | "Budget vs Actual" | "Needs Attention".
- Every row/card uses the shared **Status Rail** (per `DESIGN.md`) colored by the Variance
  Engine's classification — same visual motif already used across Scheduler/Workflows/
  Notifications/CRM, so Performance reads as part of the same platform.
- Row click opens a drawer: detail, trend (period-over-period), related Achievements.

**Rules / logic**
- Tenant-scoped automatically (DB-per-tenant boundary, no `tenant_id` column).
- "Needs attention" always surfaces breaches first regardless of chosen sort, mirroring the
  SLA-breach-first rule already established in CRM's dashboard.

## 3B. Budgeting (Entry)

- **Budget header** (`perf.budget_hdrs`): subject (`subject_type`/`subject_id`), name, fiscal
  period (year, or year+quarter), status (`draft → submitted → approved → locked`), owner.
- **Budget lines** (`perf.budget_lines`): `budget_id`, category (free lookup, e.g. "Payroll,"
  "Marketing"), period slice (typically month), `amount_planned`.
- **Actual sourcing (how "actual" is determined for a budget line):**
  - **If Accounting is installed and the category is mapped**
    (`perf.budget_category_accounts` — tenant-editable, category → one or more
    `ACCOUNTING.accounts.id`, optionally scoped to a company): the Variance Engine (§3G) reads
    actual spend for the line's period directly from Accounting via
    `AccountingService::getAccountBalance(...)` (summed across mapped accounts) — no manual
    entry, no drift between "what Performance shows" and "what the books say."
  - **Otherwise** (Accounting absent, or the category isn't mapped): actual is entered
    manually per line/period (`perf.budget_actuals` — same shape as KPI Actuals Capture, §3D,
    reusing that pattern rather than inventing a second one), same as every other MVP-manual
    engine in this module.
  - A budget line's actual-sourcing mode is visible on the line itself (mapped/GL-sourced vs.
    manual) so a viewer never mistakes a manually-entered figure for a reconciled one.
- **On submit:** optionally fires `WorkflowRequested` (`workflow_code =
  performance.budget_approval`) into WNE if the tenant wants manager sign-off — Performance
  does not implement approval logic itself, same reuse pattern as every other module's WNE
  integration.
- **Locking:** an `approved` budget can be edited only by creating a new version (append-only
  history for audit — the same "never silently overwrite" principle DMS uses for document
  versions), not by mutating the locked row.

**Rules / logic**
- Mapping a category to a GL account is optional and additive — a tenant can run Performance's
  Budgeting entirely on manual actuals (its original MVP shape) and adopt GL-sourced actuals
  later, category by category, with no schema change and no disruption to budgets already
  entered.

## 3C. Targets & KPI Setup (Entry)

- **KPI definitions** (`perf.kpi_definitions`): name, unit (number/percent/currency/ratio),
  direction (`higher_is_better` / `lower_is_better`), perspective (FK to
  `perf.perspectives`), description, active flag. Tenant-editable master list, same pattern as
  CRM's `partner_role_types`.
- **Target assignment** (`perf.targets`): `kpi_id`, `subject_type`/`subject_id`, period,
  `target_value`, `stretch_value` (optional), notes. One KPI can have many targets across many
  subjects/periods — this *is* the "multi-level" mechanism: assign the same "Revenue" KPI to
  the Company subject and, separately, to each Department subject, each with its own target.

**Rules / logic**
- A KPI must be `active` to accept new target assignments, but historical targets/values on a
  deactivated KPI remain visible for reporting.

## 3D. KPI Actuals Capture (Entry — MVP manual; Future: automated)

- Simple form: pick KPI + subject + period → enter `actual_value`. One row per combination in
  `perf.kpi_values`, with `source` (`manual` in MVP; reserved values for future connectors),
  entered_by, entered_at.
- **On save:** fires `KpiValueRecorded` event → Variance Engine (3G) re-evaluates status for
  that KPI/subject/period → if it crosses into `warning`/`breach`, fires a
  `NotificationRequested` into WNE per the tenant's routing rules — Performance never sends a
  notification directly.

## 3E. OKR Management (multi-level, aligned)

- **Objective** (`perf.okr_objectives`): `cycle_id` (FK `perf.okr_cycles` — e.g. "2026 Q3"),
  `subject_type`/`subject_id` (who owns it — company/department/team/individual), objective
  text, `parent_okr_id` (nullable, self-referencing — alignment to a higher-level Objective,
  same mechanism as CRM's `parent_partner_id`), status (`on_track` / `at_risk` / `off_track` /
  `completed`).
- **Key Result** (`perf.okr_key_results`): `okr_id`, description, `metric_type`
  (numeric/percent/boolean/milestone), `start_value`, `current_value`, `target_value`,
  weight (for the Objective's overall progress %).
- **Progress:** Objective progress % = weighted average of its Key Results' progress — computed
  on read, not stored (avoids a stale-cache class of bugs; cheap query at MVP data volumes).
- Board view (Kanban by status) and List view, both using shared components per `DESIGN.md`,
  same as CRM's Lead board.
- **Alignment view:** simple indented tree (Company → Department → Team → Individual) built
  from `parent_okr_id` chains — read-only visualization in MVP, no auto-rollup math (deferred
  to Future Version per §2).

**Rules / logic**
- A child Objective's `subject_type`/`subject_id` level should logically sit below its
  parent's, but this is a UI/validation hint, not a hard DB constraint — keeps the schema
  simple and avoids hardcoding what "level" means per tenant/vertical.

## 3F. Scorecard Builder & Viewer

- **Scorecard header** (`perf.scorecard_hdrs`): name, subject, period, perspective set used.
- **Scorecard items** (`perf.scorecard_items`): `scorecard_id`, reference to either a KPI
  assignment (KPI + subject + period) or an OKR, `perspective_id`, weight, computed actual,
  computed target, computed score (via the Variance Engine, 3G), Status Rail color.
- Builder: pick perspectives → drag in KPIs/OKRs relevant to the subject → assign weights
  (must sum to 100% per perspective, validated on save).
- Viewer: classic Balanced-Scorecard grid (rows = perspectives, columns = weight/actual/
  target/score/status), plus a single overall weighted score for the subject+period.

**Rules / logic**
- A Scorecard is a *view/composition* over existing KPI and OKR data — it does not duplicate
  values, only weights and layout, so KPI actuals updated in 3D automatically reflect here.

## 3G. Variance Analysis Engine

**Purpose:** the one reusable service every other Form/Engine calls to compare actual vs. plan.

- `VarianceService::evaluate(subjectType, subjectId, metricRef, period): VarianceResult` where
  `metricRef` can be a KPI+Target, a Budget line, or a Forecast line. For a Budget line,
  `actual_value` resolves per §3B's actual-sourcing rule — from Accounting's GL (when mapped
  and installed) or from `perf.budget_actuals` (manual fallback) — `VarianceService` itself
  doesn't care which source produced the number, it just compares plan vs. actual, same as for
  any other `metricRef` type.
- Returns: `plan_value`, `actual_value`, `variance_abs`, `variance_pct`, and `status`
  (`on_track` / `warning` / `breach`), using the KPI's direction (higher/lower-is-better) or,
  for Budget, a simple over/under-spend threshold configurable per tenant (default: within 5%
  = on-track, 5–15% = warning, >15% = breach).
- Called synchronously for Dashboard/Scorecard rendering (fast aggregate query, no queue
  needed at MVP data volumes) and asynchronously on `KpiValueRecorded` / budget-actual-update
  events to decide whether a WNE notification should fire.

## 3H. Forecast (Entry)

- **Forecast header** (`perf.forecast_hdrs`): subject, linked `budget_id` (optional — a
  forecast can also stand alone against a KPI target rather than a budget), period, version
  number, method (`manual` in MVP; reserved values for Future Version statistical methods).
- **Forecast lines** (`perf.forecast_lines`): `forecast_id`, period slice, `forecast_value`.
- **Versioning:** revising a forecast creates a new version row rather than overwriting —
  same non-destructive history principle as DMS document versions and Budget locking (3B).
- Variance Engine (3G) can compare Actual vs. the **latest** forecast version by default, with
  older versions available for "how has our forecast for this period moved over time" trend
  views.

## 3I. Achievements Engine

- **Badge definitions** (`perf.badge_definitions`, tenant-editable): name, trigger rule type
  (`target_hit` / `okr_completed` / `streak_on_track`), trigger parameters (e.g. streak length),
  icon.
- **Achievement log** (`perf.achievements`): `subject_type`/`subject_id`, `badge_id`, earned_at,
  reference to the KPI/OKR/period that triggered it, `awarded_by` (nullable — null means
  system-auto-awarded, otherwise a manager's manual award).
- **Auto-award:** listens on `KpiValueRecorded`-driven Variance results and OKR
  status-changed-to-`completed` events; when a badge's trigger rule matches, writes an
  Achievement row and optionally fires a `NotificationRequested` into WNE ("congratulations"
  style) — same decoupled pattern as everywhere else.
- MVP is a factual log only — no points, no leaderboard UI (Future Version, §2).

# 4. Storage

**Database (schema `PERF`, tenant DB — consistent with `CLAUDE.md` §7A):**

Master / lookup tables
- `PERF.periods` — fiscal period definitions (year, optional quarter/month breakdown) shared
  by Budgeting/Targets/Forecast/OKR cycles.
- `PERF.perspectives` — tenant-editable Balanced-Scorecard categories (Financial, Customer,
  Process, Learning & Growth, ...).
- `PERF.kpi_definitions` — metric library (name, unit, direction, perspective_id, active flag).
- `PERF.okr_cycles` — named OKR periods (e.g. "2026 Q3").
- `PERF.badge_definitions` — Achievement rule/badge library.

Transaction / log tables
- `PERF.budget_hdrs` — header: subject, period, status, owner, version.
- `PERF.budget_lines` — `budget_id`, category, period slice, `amount_planned`.
- `PERF.budget_category_accounts` — tenant-editable mapping: category → one or more
  `ACCOUNTING.accounts.id` (informational reference, not an enforced FK, since Accounting is
  an optional install), optional company scope.
- `PERF.budget_actuals` — manual-entry fallback: `budget_line_id`, period, `actual_value`,
  entered_by, entered_at — same shape as `PERF.kpi_values`, used only when a category isn't
  GL-mapped or Accounting isn't installed.
- `PERF.targets` — `kpi_id`, subject, period, `target_value`, `stretch_value`.
- `PERF.kpi_values` — `kpi_id`, subject, period, `actual_value`, source, entered_by, entered_at.
- `PERF.okr_objectives` — `cycle_id`, subject, objective text, `parent_okr_id`, status.
- `PERF.okr_key_results` — `okr_id`, description, metric_type, start/current/target values, weight.
- `PERF.scorecard_hdrs` — name, subject, period, perspective set.
- `PERF.scorecard_items` — `scorecard_id`, metric reference (KPI or OKR), `perspective_id`,
  weight, computed actual/target/score.
- `PERF.forecast_hdrs` — subject, `budget_id` (nullable), period, version, method.
- `PERF.forecast_lines` — `forecast_id`, period slice, `forecast_value`.
- `PERF.achievements` — subject, `badge_id`, earned_at, trigger reference, awarded_by.

**Object file storage:** none required for MVP. If future needs arise (e.g. attaching a
budget justification PDF), reuse **DMS** via its standard `subject_type`/`subject_id`
attachment facade rather than building parallel storage inside Performance — same reuse rule
already applied by every other module in this platform.

# 5. Technical Notes

> All necessary technical detail to help AI Coding

**Architecture pattern:** Core module, same monolithic-modular posture as WNE/DMS/CRM/Schedule.
Exposes:
- **Internal facade/service** — `PerformanceService::setTarget()`, `::recordKpiValue()`,
  `::createBudget()`, `::createOkr()`, `::buildScorecard()` — plus the standalone
  `VarianceService::evaluate()` (3G) as the shared comparison primitive every other piece of
  the module calls internally.
- **Internal event bus** — `KpiValueRecorded`, `BudgetSubmitted`, `BudgetApproved`,
  `OkrStatusChanged`, `AchievementAwarded`, `VarianceBreachDetected` — decouples Performance
  from WNE/DMS/Schedule; none of those modules are compile-time dependencies.

**Cross-module reuse (never build a parallel path):**
- **WNE** — all threshold-breach notifications, "congratulations" achievement notices, and the
  optional budget-approval workflow route through WNE's facade/events. Performance does not
  send mail/SMS or implement approval state machines itself.
- **Accounting** — soft dependency, scoped to budget-actual sourcing only (§3B/§3G). When
  installed and a budget category is mapped to one or more GL accounts, Performance reads
  actual spend via `AccountingService::getAccountBalance(...)` instead of requiring manual
  entry. Performance never posts to Accounting, never becomes a second GL, and functions fully
  on manual actuals if Accounting is absent — same scoped-soft-dependency shape Purchase and
  Sales use toward Inventory (`PURCHASE_SPECS.md` §5, `SALES_SPECS.md` §5).
- **Schedule** — periodic review cadence ("weekly OKR check-in due," "monthly budget review")
  is a Schedule Task with `subject_type = 'performance.okr_objectives'` (or budget_hdrs),
  created by Performance but *owned/rendered* by Schedule — Performance doesn't build its own
  reminder/calendar logic.
- **DMS** — any future supporting-document attachment (budget justification, forecast
  assumptions doc) uses DMS's polymorphic `attach()` facade, not a Performance-specific upload
  path.
- **CRM** — optional, not required for MVP: a Scorecard's subject can be a CRM `partners`
  record (e.g. an account-performance scorecard for a key client), using the same
  `subject_type`/`subject_id` seam — Performance still never foreign-keys directly into CRM.

**Multi-level design decision:** deliberately *not* building a dedicated organizational
hierarchy table. Both "multi-level KPIs" and "multi-level OKRs" are achieved with mechanisms
already proven elsewhere in this codebase — polymorphic `subject_type`/`subject_id` tagging
(KPIs/Targets/Budgets) and self-referencing `parent_id` alignment (OKRs, same pattern as CRM's
`parent_partner_id`) — rather than inventing a new hierarchy concept. This is faster to ship
and avoids forcing every tenant into one rigid org-chart shape.

**Variance as a shared primitive:** `VarianceService::evaluate()` is deliberately the *only*
place plan-vs-actual math is written. Dashboard, Scorecards, and WNE breach notifications all
call it rather than each computing their own variance logic — keeps the "what counts as
on-track" rule consistent and changeable in one place.

**Tenant isolation note:** Performance tables carry **no** `tenant_id` column, consistent with
`CLAUDE.md` §4/§7's DB-per-tenant rule.

**Versioning/non-destructive history:** Budgets (on approval-lock) and Forecasts both version
forward instead of overwriting — same principle DMS uses for document versions — so "what did
we originally plan" is always answerable for audit/board-reporting purposes.

**Queues:** KPI actuals capture, budget CRUD, and OKR updates are synchronous (fast, 
user-facing). Only the "event → WNE evaluates routing rules → notification dispatch" leg is
async, and it reuses WNE's existing `notifications` queue — Performance does not need its own
queue for MVP.

**Marketability notes**
- Performance is sellable **standalone** as an "Executive Dashboard" product (budgeting +
  KPI/OKR tracking + scorecards) even before a tenant buys any vertical module — broadens the
  addressable market beyond Legal.
- Once a tenant has WNE + Schedule + CRM installed, Performance's breach alerts and review
  reminders "just work" with zero extra setup — a strong upsell story ("you already have the
  plumbing, turn on Performance and get a real management dashboard").
- The Variance Engine + Scorecard combination is a concrete, demoable "board meeting in one
  screen" pitch — a high-value, easy-to-show feature for a conservative legal-buyer audience
  that DESIGN.md's brief already targets (trust, precision, status-at-a-glance).
- Achievements, kept factual rather than game-ified, fits the calm/professional tone in
  `DESIGN.md` §5 (no forced friendliness) while still giving a positive, sellable "recognize
  good performance" talking point.
- Once a tenant also has Accounting installed, Performance's budget-vs-actual numbers can
  reconcile to real posted GL data instead of a manually-typed figure — the same "one true
  number, not two disagreeing reports" trust story already used for Sales/Accounting AR and
  Purchase/Accounting AP.

**Suggested build order for Claude Code:** 3C (KPI library + Targets) → 3D (actuals capture,
manual) → 3G (Variance Engine — highest leverage, everything else calls it) → 3B (Budgeting) →
3H (Forecast, reuses Budget-line shape) → 3E (OKRs) → 3F (Scorecards, composes 3C/3D/3E) →
3A (Dashboard, ties everything together) → **ship** — then 3I (Achievements) and revisit
Future Version items (automated connectors, predictive forecasting, cascading OKR rollup math)
once there's real usage volume.
